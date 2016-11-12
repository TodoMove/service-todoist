<?php

namespace TodoMove\Service\Todoist;

use GuzzleHttp\Client;

use GuzzleHttp\Exception\ClientException;
use Ramsey\Uuid\Uuid;
use TodoMove\Intercessor\Contracts\Service\Reader;
use TodoMove\Intercessor\Folder;
use TodoMove\Intercessor\Project;
use TodoMove\Intercessor\Repeat;
use TodoMove\Intercessor\Service\AbstractWriter;
use TodoMove\Intercessor\Tag;
use TodoMove\Intercessor\Task;

/**
 *
 * https://developer.todoist.com/
 *
 * Need to create the folders last, as to create a folder you need to pass in list_ids returned from POST /lists
 *
 * Projects = Projects (no repeating or label)
 * Tasks = Items (needs project_id, content, date_string (YYYY-MM-DD), date_lang (en), due_date_utc (YYYY-MM-DDTHH:MM, UTC), priority (4 very urgent == flagged)
 * Tasks->notes() = Notes (premium only) - could we link to unique safe url to put people's notes in?
 * Tags = Labels (premium only) - could we use hashtags in title for tags like wunderlist?
 *
 * Tasks->flagged() = Task->priority=4
 * Tasks->tags() = Task->labels=[label_id1, label_id2]
 */

class Writer extends AbstractWriter
{
    protected $client;
    private $token;
    private $isPremium = false;

    /**
     * @param string $clientId - From your Todoist App
     * @param string $token - OAuth token
     */
    public function __construct($clientId = '', $token = '')
    {
        $this->name('Todoist');
        $this->token = $token;

        $this->client = new Client([
            'base_uri' => 'https://todoist.com/API/v7/sync',
            'headers' => [
                'X-Access-Token' => $token,
                'X-Client-ID' => $clientId
            ],
        ]);

        $this->isPremium = $this->isPremium();
    }

    private function isPremium()
    {
        try {
            $response = json_decode($this->client->get('', [
                'form_params' => [
                    'token' => $this->token,
                    'sync_token' => '*',
                    'resource_types' => '["user"]'
                ],
            ])->getBody(), true);
        } catch (Exception $e) {
            $this->isPremium = false;
            return $this->isPremium;
        }

        $this->isPremium = $response['user']['is_premium'];

        return $this->isPremium;
    }

    // TODO: How will we handle live updates of progress?  We'll need to mark each item as 'synced', then laravel echo can be used to say what's been synced?
    // TODO: Maybe we need an event/callback: $project->onSync(function($project) { echo::default('project.synced', $project); });

    /** @inheritdoc */
    public function syncFrom(Reader $reader)
    {
        $this->syncTags($reader->tags());
        $this->syncFolders($reader->folders());
        $this->syncProjects($reader->projects());
        $this->syncTasks($reader->tasks());
    }

    public function syncFolder(Folder $folder)
    {
        return $this->syncFolders([$folder]);
    }

    public function syncProject(Project $project)
    {
        return $this->syncProjects([$project]);
    }

    private function makeMultipleRequest(array $commands, $attempts = 0)
    {
        try {
            $result = $this->client->post('', [
                'form_params' => [
                    'token' => $this->token,
                    'commands' => json_encode($commands),
                ],
            ]);
        } catch (ClientException $e) {
            sleep(1);
            if ($attempts > 5) {
                Throw new \Exception('Attempted URL 5 times, it will not succeed ' . $e->getMessage() . ': ' . print_r($commands, true));
            }

            return $this->makeMultipleRequest($commands, ++$attempts);
        }

        $response = json_decode($result->getBody(), true);

        return $response;
    }

    private function convertRepeat(Repeat $repeat)
    {
        $dateString = 'every';
        $dateString .= ($repeat->interval() > 1) ? ' ' . $repeat->interval() : '';
        $dateString .= ' ';

        switch($repeat->type()) {
            case Repeat::DAY:
                $append = 'day';
                break;
            case Repeat::WEEK:
                $append = 'week';
                break;
            case Repeat::MONTH:
                $append = 'month';
                break;
            case Repeat::YEAR:
                $append = 'year';
                break;
            default:
                Throw new \Exception('Invalid repeat type');
        }

        $dateString .= $append;
        $dateString .= ($repeat->interval() > 1) ? 's' : '';

        return $dateString;
    }

    protected function buildTaskCommand(Task $task)
    {
        $labelIds = [];

        // Make sure to sync tags before tasks
        foreach ($task->tags() as $tag) {
            if (!empty($tag->meta('todoist-id'))) {
                $labelIds[] = $tag->meta('todoist-id');
            }
        }

        $data = [
            'project_id' => $task->project()->meta('todoist-id'),
            'content' => $task->title(), // We could append $this->taskTags($task) here, but Todoist actually stores the labels/tags against a task, but just doesn't show them if you're not premium
            'starred' => $task->flagged(),
            'completed' => $task->completed(),
            'date_lang' => 'en',
            'labels' => $labelIds,
            'indent' => 1,
        ];

        if ($task->due()) {
            $data['due_date_utc'] = $task->due()->format('Y-m-d\TH:i');
            $data['date_string'] = $task->due()->format('Y-m-d');
        } elseif($task->defer()) {
            $data['due_date_utc'] = $task->defer()->format('Y-m-d\TH:i');
            $data['date_string'] = $task->defer()->format('Y-m-d');
        }

        if ($task->repeat()) {
            $data['date_string'] = $this->convertRepeat($task->repeat());
        } elseif ($task->project()->repeat()) {
            $data['date_string'] = $this->convertRepeat($task->project()->repeat());
        }

        return [
            'type' => 'item_add',
            'uuid' => $this->uuid(),
            'temp_id' => $task->id(),
            'args' => $data
        ];
    }

    public function syncTask(Task $task)
    {
        return $this->syncTasks([$task]);
    }

    // If the user doesn't have premium, we'll put the tags in the task title (except hashtags are project names so we'll use %)
    public function taskTags(Task $task)
    {
        if ($this->isPremium) { // We're premium, we don't need these fake tags
            return '';
        }

        $tags = '';
        /** @var Tag $tag */
        foreach ($task->tags() as $tag) {
            $tags .= ' %' . $tag->title();
        }

        Return $tags;
    }

    public function buildNotecommand(Task $task) // TODO: Figure out non-premium way of handling this
    {
        if (!$this->isPremium) {
            return null;
        }

        return [
            'type' => 'note_add',
            'uuid' => $this->uuid(),
            'temp_id' => $this->uuid(),
            'args' => [
                'content' => $task->notes(),
                'item_id' => $task->id()
            ]
        ];
    }

    public function syncTag(Tag $tag)
    {
        return $this->syncTags([$tag]);
    }

    protected function uuid()
    {
        return Uuid::uuid4()->toString();
    }

    public function syncFolders(array $folders)
    {
        $commands = [];

        /** @var Folder $folder */
        foreach ($folders as $folder) {
            $commands[] = [
                'type' => 'project_add',
                'uuid' => $this->uuid(),
                'temp_id' => $folder->id(),
                'args' => ['name' => $folder->name(), 'indent' => 1]
            ];
        }

        $mappings = $this->makeMultipleRequest($commands)['temp_id_mapping'];

        foreach ($folders as $folder) {
            $folder->meta('todoist-id', $mappings[$folder->id()]);
        }

        return $this;
    }

    public function syncProjects(array $projects)
    {
        $commands = [];

        /** @var Project $project */
        foreach ($projects as $project) {
            $indent = ($project->folder()) ? 2 : 1; // If it has a folder, it's indent is higher

            $commands[] = [
                'type' => 'project_add',
                'uuid' => $this->uuid(),
                'temp_id' => $project->id(),
                'args' => ['name' => $project->name(), 'indent' => $indent]
            ];
        }

        $mappings = $this->makeMultipleRequest($commands)['temp_id_mapping'];

        foreach ($projects as $project) {
            $project->meta('todoist-id', $mappings[$project->id()]);
        }

        return $this;
    }

    /**
     * @param Tag[] $tags
     * @return $this
     */
    public function syncTags(array $tags)
    {
        $commands = [];

        /** @var Tag $tag */
        foreach ($tags as $tag) {
            $commands[] = [
                'type' => 'label_add',
                'uuid' => $this->uuid(),
                'temp_id' => $tag->id(),
                'args' => ['name' => $tag->title()]
            ];
        }

        $mappings = $this->makeMultipleRequest($commands)['temp_id_mapping'];

        foreach ($tags as $tag) {
            $tag->meta('todoist-id', $mappings[$tag->id()]);
        }

        return $this;
    }

    public function syncTasks(array $tasks)
    {
        $commands = [];
        foreach ($tasks as $task) {
            $commands[] = $this->buildTaskCommand($task);
            $noteCommand = $this->buildNotecommand($task);
            if (!is_null($noteCommand)) {
                $commands[] = $noteCommand;
            }
        }

        $mappings = $this->makeMultipleRequest($commands)['temp_id_mapping'];

        return $this;
    }
}
