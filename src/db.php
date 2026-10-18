<?php
defined('ROOT') || exit; // pas d'accès direct
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        exit("L'extension PHP pdo_sqlite n'est pas chargée. Lance l'app avec start.bat (ou : php -d extension=pdo_sqlite -S localhost:8000 router.php).");
    }
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0777, true);

    $pdo = new PDO('sqlite:' . DATA_DIR . '/muscu.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS exercises (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        name_fr TEXT,
        body_part TEXT,
        equipment TEXT,
        target TEXT,
        secondary TEXT,
        instructions_fr TEXT,
        steps_fr TEXT,
        steps_en TEXT,
        image TEXT,
        gif TEXT,
        is_custom INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS exercise_muscles (
        exercise_id TEXT NOT NULL REFERENCES exercises(id) ON DELETE CASCADE,
        muscle TEXT NOT NULL,
        role TEXT NOT NULL CHECK (role IN ('primary', 'secondary')),
        PRIMARY KEY (exercise_id, muscle)
    );
    CREATE TABLE IF NOT EXISTS templates (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        notes TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
    );
    CREATE TABLE IF NOT EXISTS template_exercises (
        id INTEGER PRIMARY KEY,
        template_id INTEGER NOT NULL REFERENCES templates(id) ON DELETE CASCADE,
        position INTEGER NOT NULL DEFAULT 0,
        label TEXT,
        exercise_id TEXT NOT NULL REFERENCES exercises(id),
        kind TEXT,
        sets INTEGER NOT NULL DEFAULT 3,
        reps_min INTEGER,
        reps_max INTEGER,
        rest_min INTEGER,
        rest_max INTEGER,
        notes TEXT,
        overload_sets INTEGER,
        overload_reps INTEGER,
        inc_min REAL,
        inc_max REAL
    );
    CREATE TABLE IF NOT EXISTS workouts (
        id INTEGER PRIMARY KEY,
        template_id INTEGER REFERENCES templates(id) ON DELETE SET NULL,
        name TEXT NOT NULL,
        started_at TEXT NOT NULL,
        ended_at TEXT,
        notes TEXT
    );
    CREATE TABLE IF NOT EXISTS workout_exercises (
        id INTEGER PRIMARY KEY,
        workout_id INTEGER NOT NULL REFERENCES workouts(id) ON DELETE CASCADE,
        exercise_id TEXT NOT NULL REFERENCES exercises(id),
        template_exercise_id INTEGER REFERENCES template_exercises(id) ON DELETE SET NULL,
        position INTEGER NOT NULL DEFAULT 0,
        label TEXT,
        notes TEXT
    );
    CREATE TABLE IF NOT EXISTS sets (
        id INTEGER PRIMARY KEY,
        workout_exercise_id INTEGER NOT NULL REFERENCES workout_exercises(id) ON DELETE CASCADE,
        set_number INTEGER NOT NULL,
        weight REAL,
        reps INTEGER,
        rpe REAL,
        done INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS bodyweight (
        date TEXT PRIMARY KEY,
        weight REAL NOT NULL,
        body_fat REAL,
        notes TEXT
    );
    CREATE TABLE IF NOT EXISTS measurements (
        id INTEGER PRIMARY KEY,
        date TEXT NOT NULL,
        site TEXT NOT NULL,
        value REAL NOT NULL,
        UNIQUE (date, site)
    );
    CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_em_muscle ON exercise_muscles(muscle);
    CREATE INDEX IF NOT EXISTS idx_we_workout ON workout_exercises(workout_id);
    CREATE INDEX IF NOT EXISTS idx_we_exercise ON workout_exercises(exercise_id);
    CREATE INDEX IF NOT EXISTS idx_sets_we ON sets(workout_exercise_id);
    CREATE INDEX IF NOT EXISTS idx_workouts_start ON workouts(started_at);
    CREATE INDEX IF NOT EXISTS idx_te_template ON template_exercises(template_id);
    SQL);

    if ((int)$pdo->query('SELECT COUNT(*) FROM exercises')->fetchColumn() === 0) {
        import_exercises($pdo, DATA_DIR . '/exercises.json');
    }
    // Quelques noms du dataset sont mal encodés (« 45в° »).
    $pdo->exec("UPDATE exercises SET name = REPLACE(name, 'в°', '°') WHERE name LIKE '%в°%'");
    add_columns($pdo, 'template_exercises', ['focus' => 'TEXT', 'rpe_min' => 'REAL', 'rpe_max' => 'REAL']);
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 1) migrate_v1($pdo);
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 2) migrate_v2($pdo);
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 3) migrate_v3($pdo);
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 4) migrate_v4($pdo);
}

/** Importe le dataset hasaneyldrm/exercises-dataset (data/exercises.json). */
function import_exercises(PDO $pdo, string $file): int
{
    if (!is_file($file)) return 0;
    ini_set('memory_limit', '768M');
    set_time_limit(300);
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return 0;

    $insEx = $pdo->prepare('INSERT OR REPLACE INTO exercises
        (id, name, body_part, equipment, target, secondary, instructions_fr, steps_fr, steps_en, image, gif, is_custom)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,0)');
    $insM = $pdo->prepare('INSERT OR IGNORE INTO exercise_muscles (exercise_id, muscle, role) VALUES (?,?,?)');

    $pdo->beginTransaction();
    foreach ($data as $e) {
        $secondary = $e['secondary_muscles'] ?? [];
        $insEx->execute([
            $e['id'], $e['name'], $e['body_part'] ?? $e['category'] ?? null, $e['equipment'] ?? null,
            $e['target'] ?? null, json_encode($secondary, JSON_UNESCAPED_UNICODE),
            $e['instructions']['fr'] ?? $e['instructions']['en'] ?? null,
            json_encode($e['instruction_steps']['fr'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($e['instruction_steps']['en'] ?? [], JSON_UNESCAPED_UNICODE),
            $e['image'] ?? null, $e['gif_url'] ?? null,
        ]);
        $primary = map_muscle($e['target'] ?? null);
        if ($primary) $insM->execute([$e['id'], $primary, 'primary']);
        $others = array_merge([$e['muscle_group'] ?? null], $secondary);
        foreach ($others as $raw) {
            $m = map_muscle($raw);
            if ($m && $m !== $primary) $insM->execute([$e['id'], $m, 'secondary']);
        }
    }
    $pdo->commit();
    return count($data);
}

/** Ajoute les colonnes manquantes (migration douce des bases existantes). */
function add_columns(PDO $pdo, string $table, array $cols): void
{
    $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    foreach ($cols as $name => $type) {
        if (!in_array($name, $have, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN $name $type");
    }
}

/**
 * v1 : comptes utilisateurs. Chaque séance, programme, pesée, mensuration et réglage appartient à un compte.
 * Les données existantes restent sans propriétaire (user_id NULL) jusqu'à la création du premier compte,
 * qui les récupère (voir claim_orphans).
 */
function migrate_v1(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        name TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
        last_login_at TEXT
    );
    CREATE TABLE IF NOT EXISTS user_settings (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        key TEXT NOT NULL,
        value TEXT,
        PRIMARY KEY (user_id, key)
    );
    CREATE TABLE IF NOT EXISTS login_attempts (
        email TEXT NOT NULL COLLATE NOCASE,
        at INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts(email, at);

    CREATE TABLE bodyweight_v1 (
        user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        date TEXT NOT NULL,
        weight REAL NOT NULL,
        body_fat REAL,
        notes TEXT,
        UNIQUE (user_id, date)
    );
    INSERT INTO bodyweight_v1 (date, weight, body_fat, notes) SELECT date, weight, body_fat, notes FROM bodyweight;
    DROP TABLE bodyweight;
    ALTER TABLE bodyweight_v1 RENAME TO bodyweight;

    CREATE TABLE measurements_v1 (
        id INTEGER PRIMARY KEY,
        user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        date TEXT NOT NULL,
        site TEXT NOT NULL,
        value REAL NOT NULL,
        UNIQUE (user_id, date, site)
    );
    INSERT INTO measurements_v1 (id, date, site, value) SELECT id, date, site, value FROM measurements;
    DROP TABLE measurements;
    ALTER TABLE measurements_v1 RENAME TO measurements;
    SQL);
    add_columns($pdo, 'templates', ['user_id' => 'INTEGER REFERENCES users(id) ON DELETE CASCADE']);
    add_columns($pdo, 'workouts', ['user_id' => 'INTEGER REFERENCES users(id) ON DELETE CASCADE']);
    add_columns($pdo, 'exercises', ['owner_id' => 'INTEGER REFERENCES users(id) ON DELETE CASCADE']);
    $pdo->exec(<<<SQL
    CREATE INDEX IF NOT EXISTS idx_workouts_user ON workouts(user_id, started_at);
    CREATE INDEX IF NOT EXISTS idx_templates_user ON templates(user_id);
    CREATE INDEX IF NOT EXISTS idx_exercises_owner ON exercises(owner_id);
    SQL);
    // Base déjà utilisée avant les comptes : le catalogue a déjà reçu les renommages / muscles des programmes.
    if ($pdo->query("SELECT 1 FROM settings WHERE key = 'seeded_programs'")->fetchColumn()) {
        $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('catalog_tuned', '1')");
    }
    $pdo->exec('PRAGMA user_version = 1');
    $pdo->commit();
    $pdo->exec('PRAGMA foreign_keys = ON');
}

/** v2 : activités cardio / sport (marche, course, vélo, rameur…). */
function migrate_v2(PDO $pdo): void
{
    $pdo->beginTransaction();
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS activities (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        type TEXT NOT NULL,
        started_at TEXT NOT NULL,
        duration_s INTEGER NOT NULL CHECK (duration_s > 0),
        distance_km REAL CHECK (distance_km IS NULL OR distance_km >= 0),
        intensity INTEGER NOT NULL DEFAULT 2 CHECK (intensity BETWEEN 1 AND 3),
        calories INTEGER,
        avg_hr INTEGER,
        elevation_m INTEGER,
        notes TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
    );
    CREATE INDEX IF NOT EXISTS idx_activities_user ON activities(user_id, started_at);
    PRAGMA user_version = 2;
    SQL);
    $pdo->commit();
}

/** v3 : supersets stockés dans superset_group (avant : déduits du libellé « 2A / 2B »). */
function migrate_v3(PDO $pdo): void
{
    $pdo->beginTransaction();
    add_columns($pdo, 'template_exercises', ['superset_group' => 'INTEGER']);
    add_columns($pdo, 'workout_exercises', ['superset_group' => 'INTEGER']);
    foreach ($pdo->query('SELECT id FROM templates')->fetchAll(PDO::FETCH_COLUMN) as $id) ss_from_labels('template', (int)$id);
    foreach ($pdo->query('SELECT id FROM workouts')->fetchAll(PDO::FETCH_COLUMN) as $id) ss_from_labels('workout', (int)$id);
    $pdo->exec('PRAGMA user_version = 3');
    $pdo->commit();
}

/** v4 : repos après l'exercice (transition) et notes de réglage machine par utilisateur. */
function migrate_v4(PDO $pdo): void
{
    $pdo->beginTransaction();
    add_columns($pdo, 'template_exercises', ['rest_after' => 'INTEGER']);
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS exercise_notes (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        exercise_id TEXT NOT NULL REFERENCES exercises(id) ON DELETE CASCADE,
        note TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
        PRIMARY KEY (user_id, exercise_id)
    );
    PRAGMA user_version = 4;
    SQL);
    $pdo->commit();
}
