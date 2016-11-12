<?php
require __DIR__ . '/vendor/autoload.php';

$clientId = getenv('CLIENTID');
$token = getenv('TOKEN');
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
$task->notes('My notes, my notes, my notes are on fire')
    ->flagged(true)
    ->project($project2)
    ->due(
        (new \DateTime())->add(new \DateInterval('P3D'))
    )
    ->tags($tags)
    ->repeat($repeat);


$task2 = new \TodoMove\Intercessor\Task('TWOTWO Mah task ' . rand(10090, 99999));
$task2->notes('TWO TWOMy notes, my notes, my notes are on fire')
    ->flagged(true)
    ->project($project)
    ->due(
        (new \DateTime())->add(new \DateInterval('P30D'))
    )
    ->tags($tags);

$project2->task($task);

$folder = new \TodoMove\Intercessor\ProjectFolder('Folders so cool');
$folder2 = new \TodoMove\Intercessor\ProjectFolder('Folders rock');
$folder3 = new \TodoMove\Intercessor\ProjectFolder('Folders oh yea');

$folder->projects([
    $project,
]);

$folder2->projects([
    $project2,
]);

$folder3->projects([
    $project3,
]);

$project->folder($folder);
$project2->folder($folder2);
$project3->folder($folder3);

// Write these Intercessor classes to Todoist
$writer->syncTags([$tag, $tag2, $tag3]);
$writer->syncFolders([$folder2]);
$writer->syncProjects([$project, $project2, $project3]);
$writer->syncTasks([$task, $task2]);

echo "Done" . PHP_EOL;