<?php
defined('ROOT') || exit; // pas d'accès direct
// Groupes musculaires de l'app (clé => [libellé, région]).
const MUSCLES = [
    'pectoraux'   => ['Pectoraux', 'haut'],
    'epaules'     => ['Épaules', 'haut'],
    'dorsaux'     => ['Dorsaux', 'haut'],
    'haut_dos'    => ['Haut du dos', 'haut'],
    'trapezes'    => ['Trapèzes', 'haut'],
    'biceps'      => ['Biceps', 'haut'],
    'triceps'     => ['Triceps', 'haut'],
    'avant_bras'  => ['Avant-bras', 'haut'],
    'dentele'     => ['Dentelé', 'haut'],
    'coiffe'      => ['Coiffe des rotateurs', 'haut'],
    'cou'         => ['Cou', 'haut'],
    'abdos'       => ['Abdominaux', 'tronc'],
    'obliques'    => ['Obliques', 'tronc'],
    'lombaires'   => ['Lombaires', 'tronc'],
    'flechisseurs'=> ['Fléchisseurs de hanche', 'tronc'],
    'quadriceps'  => ['Quadriceps', 'bas'],
    'fessiers'    => ['Fessiers', 'bas'],
    'ischios'     => ['Ischio-jambiers', 'bas'],
    'adducteurs'  => ['Adducteurs', 'bas'],
    'abducteurs'  => ['Abducteurs / Moyen fessier', 'bas'],
    'mollets'     => ['Mollets', 'bas'],
    'cardio'      => ['Cardio', 'cardio'],
];

const REGIONS = [
    'haut'   => 'Haut du corps',
    'tronc'  => 'Tronc',
    'bas'    => 'Bas du corps',
    'cardio' => 'Cardio',
];

// Muscles du dataset (anglais) => muscles de l'app. null = ignoré dans les stats.
const MUSCLE_MAP = [
    'quads' => 'quadriceps', 'quadriceps' => 'quadriceps',
    'glutes' => 'fessiers',
    'hamstrings' => 'ischios',
    'calves' => 'mollets', 'soleus' => 'mollets',
    'adductors' => 'adducteurs', 'inner thighs' => 'adducteurs', 'groin' => 'adducteurs',
    'abductors' => 'abducteurs',
    'pectorals' => 'pectoraux', 'chest' => 'pectoraux', 'upper chest' => 'pectoraux',
    'delts' => 'epaules', 'shoulders' => 'epaules', 'deltoids' => 'epaules', 'rear deltoids' => 'epaules',
    'lats' => 'dorsaux', 'latissimus dorsi' => 'dorsaux',
    'upper back' => 'haut_dos', 'back' => 'haut_dos', 'rhomboids' => 'haut_dos',
    'traps' => 'trapezes', 'trapezius' => 'trapezes', 'levator scapulae' => 'trapezes',
    'biceps' => 'biceps', 'brachialis' => 'biceps',
    'triceps' => 'triceps',
    'forearms' => 'avant_bras', 'wrists' => 'avant_bras', 'wrist flexors' => 'avant_bras',
    'wrist extensors' => 'avant_bras', 'grip muscles' => 'avant_bras', 'hands' => 'avant_bras',
    'abs' => 'abdos', 'abdominals' => 'abdos', 'lower abs' => 'abdos', 'core' => 'abdos',
    'obliques' => 'obliques',
    'lower back' => 'lombaires', 'spine' => 'lombaires',
    'hip flexors' => 'flechisseurs',
    'serratus anterior' => 'dentele',
    'rotator cuff' => 'coiffe',
    'sternocleidomastoid' => 'cou',
    'cardiovascular system' => 'cardio',
    'ankles' => null, 'ankle stabilizers' => null, 'feet' => null, 'shins' => null,
];

const BODY_PARTS_FR = [
    'upper arms' => 'Bras', 'upper legs' => 'Cuisses', 'back' => 'Dos', 'waist' => 'Taille / abdos',
    'chest' => 'Pectoraux', 'shoulders' => 'Épaules', 'lower legs' => 'Mollets', 'lower arms' => 'Avant-bras',
    'cardio' => 'Cardio', 'neck' => 'Cou', 'custom' => 'Perso',
];

const EQUIPMENT_FR = [
    'body weight' => 'Poids du corps', 'dumbbell' => 'Haltères', 'cable' => 'Poulie', 'barbell' => 'Barre',
    'leverage machine' => 'Machine', 'band' => 'Élastique', 'smith machine' => 'Smith machine',
    'kettlebell' => 'Kettlebell', 'weighted' => 'Lesté', 'stability ball' => 'Swiss ball',
    'ez barbell' => 'Barre EZ', 'assisted' => 'Assisté', 'sled machine' => 'Presse / Hack',
    'medicine ball' => 'Medecine ball', 'rope' => 'Corde', 'roller' => 'Rouleau',
    'resistance band' => 'Bande de résistance', 'bosu ball' => 'Bosu', 'olympic barbell' => 'Barre olympique',
    'wheel roller' => 'Roue abdos', 'upper body ergometer' => 'Ergomètre bras', 'skierg machine' => 'SkiErg',
    'hammer' => 'Masse', 'stationary bike' => 'Vélo', 'tire' => 'Pneu', 'trap bar' => 'Trap bar',
    'elliptical machine' => 'Elliptique', 'stepmill machine' => 'Stepper', 'other' => 'Autre',
];

// Mensurations suivies (clé => libellé).
const MEASURE_SITES = [
    'cou' => 'Cou', 'epaules' => 'Épaules', 'poitrine' => 'Poitrine',
    'bras_g' => 'Bras gauche', 'bras_d' => 'Bras droit', 'avant_bras' => 'Avant-bras',
    'taille' => 'Tour de taille', 'hanches' => 'Hanches',
    'cuisse_g' => 'Cuisse gauche', 'cuisse_d' => 'Cuisse droite',
    'mollet_g' => 'Mollet gauche', 'mollet_d' => 'Mollet droit',
];

// Volume hebdo conseillé par muscle (séries effectives / semaine).
const WEEKLY_LOW = 10;
const WEEKLY_HIGH = 20;

function muscle_label(string $key): string
{
    return MUSCLES[$key][0] ?? $key;
}

function map_muscle(?string $raw): ?string
{
    if ($raw === null) return null;
    $raw = strtolower(trim($raw));
    return array_key_exists($raw, MUSCLE_MAP) ? MUSCLE_MAP[$raw] : null;
}
