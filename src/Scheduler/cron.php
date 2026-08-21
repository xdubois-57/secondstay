<?php

declare(strict_types=1);

/**
 * Point d'entrée des tâches périodiques (ARCHITECTURE.md §23).
 *
 * Une seule entrée cron suffit, à appeler aussi souvent que l'hébergement
 * l'autorise — le produit décide lui-même de ce qui est dû :
 *
 *     php /chemin/vers/secondstay/src/Scheduler/cron.php
 *
 * Ce fichier vit sous `src/` et non à la racine ou sous `public/` : `src/` est
 * refusé par le `.htaccess` **et** par la politique de chemins publics, de
 * sorte qu'un planificateur ne devienne jamais une URL déclenchable par
 * accident. La garde `PHP_SAPI` en est la troisième ligne : sur un hébergement
 * dont la configuration serveur aurait été perdue, le fichier ne répond rien.
 *
 * Options :
 *   --task=<code>   n'exécute qu'une tâche, sans attendre son intervalle
 *   --list          affiche l'état des tâches sans rien exécuter
 */

use SecondStay\Core\Kernel;
use SecondStay\Scheduler\ScheduledTask;
use SecondStay\Scheduler\SchedulerFactory;
use SecondStay\Scheduler\TaskStateRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);

$autoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "SecondStay : vendor/autoload.php est absent.\n");
    exit(1);
}

require $autoload;

$only = null;
$list = false;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--task=')) {
        $only = substr($argument, 7);
    } elseif ($argument === '--list') {
        $list = true;
    }
}

try {
    $container = (new Kernel($projectRoot))->boot();
} catch (Throwable $exception) {
    fwrite(STDERR, 'SecondStay : démarrage impossible — ' . $exception->getMessage() . "\n");
    exit(1);
}

if ($list) {
    foreach ($container->get(TaskStateRepository::class)->all() as $state) {
        printf(
            "%-16s %-20s %-8s %s\n",
            $state->task->value,
            $state->lastRunAt ?? '—',
            $state->lastStatus,
            $state->lastDetail
        );
    }
    exit(0);
}

$scheduler = SchedulerFactory::build($container);

if ($only !== null) {
    $task = ScheduledTask::tryFromCode($only);
    if ($task === null) {
        fwrite(STDERR, 'SecondStay : tâche inconnue — ' . $only . "\n");
        exit(2);
    }

    $results = [$scheduler->runNow($task)];
} else {
    $results = $scheduler->runDue();
}

$failed = 0;
foreach ($results as $result) {
    printf(
        "%-16s %-8s %-40s %d ms\n",
        $result['task'],
        $result['status'],
        $result['detail'] . ($result['count'] > 0 ? ' (' . $result['count'] . ')' : ''),
        $result['duration_ms']
    );

    if ($result['status'] === 'error') {
        $failed++;
    }
}

if ($results === []) {
    echo "Aucune tâche due.\n";
}

// Un code de sortie non nul permet à l'hébergeur d'alerter : une tâche en
// échec silencieux ne serait découverte que le jour où elle manquerait.
exit($failed > 0 ? 1 : 0);
