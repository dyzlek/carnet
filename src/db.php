<?php
defined('ROOT') || exit; // pas d'accès direct
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    if (is_mysql()) {
        if (!pdo_driver('mysql')) db_fail("L'extension PHP pdo_mysql n'est pas chargée sur ce serveur.");
        try {
            $pdo = new PrefixedPDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_EMULATE_PREPARES => false,  // entiers et décimaux renvoyés en nombres, comme avec SQLite
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            error_log('[db] ' . $e->getMessage());
            db_fail('Connexion à MySQL impossible : vérifie DB_HOST, DB_NAME, DB_USER et DB_PASS dans config.php.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // || = concaténation comme en SQLite ; pas de ONLY_FULL_GROUP_BY ; heure locale du PHP
        $pdo->exec("SET SESSION sql_mode = 'PIPES_AS_CONCAT,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        migrate_mysql($pdo);
        return $pdo;
    }

    if (!pdo_driver('sqlite')) {
        db_fail(PHP_SAPI === 'cli-server'
            ? "L'extension PHP pdo_sqlite n'est pas chargée. Lance l'app avec start.bat (ou : php -d extension=pdo_sqlite -S localhost:8000 router.php)."
            : "L'extension PHP pdo_sqlite n'est pas disponible sur ce serveur. Utilise une base MySQL : copie config.example.php en config.php et remplis-le (voir DEPLOIEMENT.md).");
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

/**
 * MySQL : la base peut être partagée avec d'autres projets (WordPress, PrestaShop… sur le serveur de l'IUT).
 * Toutes les tables de Carnet portent donc le préfixe DB_PREFIX (« carnet_ » par défaut) : il est ajouté
 * automatiquement après FROM / JOIN / INTO / UPDATE / TABLE / REFERENCES, sans toucher aux colonnes
 * (template_exercises.sets) ni aux alias (AS workouts).
 */
final class PrefixedPDO extends PDO
{
    private const TABLES = 'users|settings|user_settings|login_attempts|exercises|exercise_muscles|exercise_notes|templates|'
        . 'template_exercises|workouts|workout_exercises|sets|bodyweight|measurements|activities';

    public static function prefix(string $sql): string
    {
        if (DB_PREFIX === '') return $sql;
        return preg_replace('/\b(FROM|JOIN|INTO|UPDATE|REFERENCES|TABLE(?:\s+IF\s+NOT\s+EXISTS)?)(\s+)`?(' . self::TABLES . ')`?(?![\w.])/i',
            '$1$2' . DB_PREFIX . '$3', $sql);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(self::prefix($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $fetchMode === null ? parent::query(self::prefix($query)) : parent::query(self::prefix($query), $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(self::prefix($statement));
    }
}

/**
 * Pilote PDO disponible ? (et non extension_loaded() : OPcache peut figer son résultat à la compilation,
 * et sous Windows le cache est partagé entre les serveurs PHP lancés avec des extensions différentes)
 */
function pdo_driver(string $name): bool
{
    return class_exists('PDO') && in_array($name, PDO::getAvailableDrivers(), true);
}

function is_mysql(): bool
{
    return DB_DRIVER === 'mysql';
}

function db_fail(string $msg): never
{
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

/** « INSERT OR IGNORE » (SQLite) / « INSERT IGNORE » (MySQL) ; idem pour REPLACE. */
function insert_or(string $mode): string
{
    return is_mysql() ? ($mode === 'REPLACE' ? 'REPLACE' : 'INSERT IGNORE') : "INSERT OR $mode";
}

/**
 * Requête « insérer ou mettre à jour » : ON CONFLICT (SQLite) / ON DUPLICATE KEY UPDATE (MySQL).
 * $keys = colonnes de la clé unique, $update = colonnes à mettre à jour si la ligne existe déjà.
 */
function upsert_sql(string $table, array $cols, array $keys, array $update): string
{
    $c = fn($n) => '`' . $n . '`';
    $sql = "INSERT INTO $table (" . implode(', ', array_map($c, $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    if (is_mysql()) return $sql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($n) => $c($n) . ' = VALUES(' . $c($n) . ')', $update));
    return $sql . ' ON CONFLICT(' . implode(', ', array_map($c, $keys)) . ') DO UPDATE SET ' . implode(', ', array_map(fn($n) => $c($n) . ' = excluded.' . $c($n), $update));
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

    $insEx = $pdo->prepare(insert_or('REPLACE') . ' INTO exercises
        (id, name, body_part, equipment, target, secondary, instructions_fr, steps_fr, steps_en, image, gif, is_custom)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,0)');
    $insM = $pdo->prepare(insert_or('IGNORE') . ' INTO exercise_muscles (exercise_id, muscle, role) VALUES (?,?,?)');

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
    if ($pdo->query("SELECT 1 FROM settings WHERE `key` = 'seeded_programs'")->fetchColumn()) {
        $pdo->exec("INSERT OR REPLACE INTO settings (`key`, value) VALUES ('catalog_tuned', '1')");
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

/**
 * MySQL / MariaDB : création directe du schéma actuel (équivalent de SQLite après migrate_v4).
 * La version est gardée dans settings.schema_version pour les migrations futures.
 */
function migrate_mysql(PDO $pdo): void
{
    if (!$pdo->query("SHOW TABLES LIKE '" . DB_PREFIX . "settings'")->fetchColumn()) {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $tables = [
            "CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login_at VARCHAR(19) NULL)",
            "CREATE TABLE settings (`key` VARCHAR(64) PRIMARY KEY, value TEXT NULL)",
            "CREATE TABLE user_settings (
                user_id INT NOT NULL, `key` VARCHAR(64) NOT NULL, value TEXT NULL,
                PRIMARY KEY (user_id, `key`),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
            "CREATE TABLE login_attempts (email VARCHAR(190) NOT NULL, at INT NOT NULL, INDEX idx_login_attempts (email, at))",
            "CREATE TABLE exercises (
                id VARCHAR(32) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                name_fr VARCHAR(255) NULL,
                body_part VARCHAR(64) NULL,
                equipment VARCHAR(64) NULL,
                target VARCHAR(64) NULL,
                secondary TEXT NULL,
                instructions_fr TEXT NULL,
                steps_fr MEDIUMTEXT NULL,
                steps_en MEDIUMTEXT NULL,
                image VARCHAR(255) NULL,
                gif VARCHAR(255) NULL,
                is_custom TINYINT NOT NULL DEFAULT 0,
                owner_id INT NULL,
                INDEX idx_exercises_owner (owner_id),
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE)",
            "CREATE TABLE exercise_muscles (
                exercise_id VARCHAR(32) NOT NULL, muscle VARCHAR(32) NOT NULL, role VARCHAR(10) NOT NULL,
                PRIMARY KEY (exercise_id, muscle), INDEX idx_em_muscle (muscle),
                FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE CASCADE)",
            "CREATE TABLE exercise_notes (
                user_id INT NOT NULL, exercise_id VARCHAR(32) NOT NULL, note TEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, exercise_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE CASCADE)",
            "CREATE TABLE templates (
                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, name VARCHAR(255) NOT NULL, notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_templates_user (user_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
            "CREATE TABLE template_exercises (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_id INT NOT NULL,
                position INT NOT NULL DEFAULT 0,
                label VARCHAR(16) NULL,
                exercise_id VARCHAR(32) NOT NULL,
                kind VARCHAR(255) NULL,
                focus VARCHAR(255) NULL,
                sets INT NOT NULL DEFAULT 3,
                reps_min INT NULL, reps_max INT NULL,
                rest_min INT NULL, rest_max INT NULL, rest_after INT NULL,
                rpe_min DOUBLE NULL, rpe_max DOUBLE NULL,
                notes TEXT NULL,
                overload_sets INT NULL, overload_reps INT NULL,
                inc_min DOUBLE NULL, inc_max DOUBLE NULL,
                superset_group INT NULL,
                INDEX idx_te_template (template_id),
                FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
                FOREIGN KEY (exercise_id) REFERENCES exercises(id))",
            "CREATE TABLE workouts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                template_id INT NULL,
                name VARCHAR(255) NOT NULL,
                started_at VARCHAR(19) NOT NULL,
                ended_at VARCHAR(19) NULL,
                notes TEXT NULL,
                INDEX idx_workouts_start (started_at), INDEX idx_workouts_user (user_id, started_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL)",
            "CREATE TABLE workout_exercises (
                id INT AUTO_INCREMENT PRIMARY KEY,
                workout_id INT NOT NULL,
                exercise_id VARCHAR(32) NOT NULL,
                template_exercise_id INT NULL,
                position INT NOT NULL DEFAULT 0,
                label VARCHAR(16) NULL,
                notes TEXT NULL,
                superset_group INT NULL,
                INDEX idx_we_workout (workout_id), INDEX idx_we_exercise (exercise_id),
                FOREIGN KEY (workout_id) REFERENCES workouts(id) ON DELETE CASCADE,
                FOREIGN KEY (exercise_id) REFERENCES exercises(id),
                FOREIGN KEY (template_exercise_id) REFERENCES template_exercises(id) ON DELETE SET NULL)",
            "CREATE TABLE sets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                workout_exercise_id INT NOT NULL,
                set_number INT NOT NULL,
                weight DOUBLE NULL, reps INT NULL, rpe DOUBLE NULL,
                done TINYINT NOT NULL DEFAULT 0,
                INDEX idx_sets_we (workout_exercise_id),
                FOREIGN KEY (workout_exercise_id) REFERENCES workout_exercises(id) ON DELETE CASCADE)",
            "CREATE TABLE bodyweight (
                user_id INT NULL, date VARCHAR(10) NOT NULL, weight DOUBLE NOT NULL, body_fat DOUBLE NULL, notes TEXT NULL,
                UNIQUE (user_id, date),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
            "CREATE TABLE measurements (
                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, date VARCHAR(10) NOT NULL, site VARCHAR(32) NOT NULL, value DOUBLE NOT NULL,
                UNIQUE (user_id, date, site),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
            "CREATE TABLE activities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type VARCHAR(32) NOT NULL,
                started_at VARCHAR(19) NOT NULL,
                duration_s INT NOT NULL,
                distance_km DOUBLE NULL,
                intensity TINYINT NOT NULL DEFAULT 2,
                calories INT NULL, avg_hr INT NULL, elevation_m INT NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_activities_user (user_id, started_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
        ];
        foreach ($tables as $sql) $pdo->exec("$sql $opts");
        $pdo->exec("INSERT INTO settings (`key`, value) VALUES ('schema_version', '4')");
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM exercises')->fetchColumn() === 0) {
        import_exercises($pdo, DATA_DIR . '/exercises.json');
    }
    $pdo->exec("UPDATE exercises SET name = REPLACE(name, 'в°', '°') WHERE name LIKE '%в°%'");
}
