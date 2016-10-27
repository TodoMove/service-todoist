<?php
require __DIR__ . '/vendor/autoload.php';

$clientId = '[client-id]';
$token = '[token]';
$writer = new TodoMove\Service\Todoist\Writer($clientId, $token);

$project = new \TodoMove\Intercessor\Project('Mah project ' . rand(10090, 99999));
$project2 = new \TodoMove\Intercessor\Project('Mah project ' . rand(10090, 99999));
$project3 = new \TodoMove\Intercessor\Project('Mah project ' . rand(10090, 99999));

$tag = new \TodoMove\Intercessor\Tag('shoppingHEYHEY');
$tag2 = new \TodoMove\Intercessor\Tag('errands');
$tag3 = new \TodoMove\Intercessor\Tag('lowenergy');

$tags = new \TodoMove\Intercessor\Tags();
$tags->add($tag);
$tags->add($tag2);
$tags->add($tag3);

$repeat = new \TodoMove\Intercessor\Repeat();
$repeat->bimonthly();

$task = new \TodoMove\Intercessor\Task('Mah task ' . rand(10090, 99999));
$task->notes('My notes, my notes, my notes are on fire')->flagged(true)->project($project2)->due((new \DateTime())->add(new \DateInterval('P3D')));
$task->tags($tags)->repeat($repeat);

$project2->task($task);

$folder = new \TodoMove\Intercessor\ProjectFolder('Folders so cool');
$folder->projects([
    $project,
    $project2,
    $project3
]);

$project->folder($folder);
$project2->folder($folder);
$project3->folder($folder);

// Write these Intercessor classes to Todoist
$writer->syncTag($tag);
$writer->syncTag($tag2);
$writer->syncTag($tag3);
$writer->syncFolder($folder);
$writer->syncTask($task);

echo "Done" . PHP_EOL;