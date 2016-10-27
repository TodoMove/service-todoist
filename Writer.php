<?php

namespace TodoMove\Service\Todoist;

use GuzzleHttp\Client;

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
        $response = json_decode($this->client->get('', [
            'form_params' => [
                'token' => $this->token,
                'sync_token' => '*',
                'resource_types' => '["user"]'
            ],
        ])->getBody(), true);

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
        $this->syncTasks($reader->tasks());
    }

    public function syncFolder(Folder $folder)
    {
        // We're actually going to add folders with an indent of 1, then projects with a folder will have an indent of 2

        $response = $this->makeRequest('project_add', ['name' => $folder->name(), 'indent' => 1]);
        $folder->meta('todoist-id', $response['id']);

        foreach ($folder->projects() as $project) {
            $this->syncProject($project); // We do it this way so they'll be in order within Todoist as projects aren't linked together they're simply indented
        }

        return $response;

    }

    public function syncProject(Project $project)
    {
        // TODO: Check for errors

        $indent = ($project->folder()) ? 2 : 1; // If it has a folder, it's indent is higher
        $response = $this->makeRequest('project_add', ['name' => $project->name(), 'indent' => $indent]);
        $project->meta('todoist-id', $response['id']);

        return $response;
    }

    private function makeRequest($type, array $args, $attempts = 0)
    {

        try {
            $temp_id = $this->uuid();
            $response = json_decode($this->client->post('', [
                'form_params' => [
                    'token' => $this->token,
                    'commands' => json_encode([
                        [
                            'type' => $type,
                            'uuid' => $this->uuid(),
                            'temp_id' => $temp_id,
                            'args' => $args
                        ]
                    ]),
                ],
            ])->getBody(), true);
        } catch (\Exception $e) { // Too many requests, we need to retry
            sleep(2);
            if ($attempts > 20) {
                Throw new \Exception('Attempted URL 20 times, it will not succeed: ' . $type . ' ' . implode(',', $args));
            }

            return $this->makeRequest($type, $args, ++$attempts);
        }

        $id = $response['temp_id_mapping'][$temp_id] ?: $response['full']['temp_id_mapping'][$temp_id];

        return [
            'id' => $id,
            'full' => $response
        ];
    }

    private function convertRepeat(Repeat $repeat) {
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

    public function syncTask(Task $task)
    {
        // TODO: Check for errors

        $labelIds = [];

        // Make sure to sync tags before tasks
        foreach ($task->tags() as $tag) {
            if (!empty($tag->meta('todoist-id'))) {
                $labelIds[] = $tag->meta('todoist-id');
            }
        }

        $data = [
            'project_id' => $task->project()->meta('todoist-id'),
            'content' => $task->title() . $this->taskTags($task),
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

        $response = $this->makeRequest('item_add', $data);

        $task->meta('todoist-id', $response['id']);

        if (!empty($task->notes())) {
            $this->addNote($task);
        }

        return $response;
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

    public function addNote(Task $task) // TODO: Figure out non-premium way of handling this
    {
        if (!$this->isPremium) {
            return '';
        }

        $response = $this->makeRequest('note_add', ['content' => $task->notes(), 'item_id' => $task->meta('todoist-id')]);

        return $response;
    }

    public function syncTag(Tag $tag)
    {
        $response = $this->makeRequest('label_add', ['name' => $tag->title()]);
        $tag->meta('todoist-id', $response['id']);

        return $response;
    }

    protected function uuid()
    {
        return Uuid::uuid4()->toString();
    }

    protected function syncFolders(array $folders)
    {
        //TODO: Loop, and use $this->syncFolder(Folder $folder) to hit appropriate API's to add folders / throw exceptions.  Handling errors will be tough?
        //TODO: error checking, counting total and synced
        foreach ($folders as $folder) {
            $this->syncFolder($folder);
        }

        return $this;
    }

    protected function syncProjects(array $projects)
    {
        //TODO: Loop, and use $this->syncProject(Project $project) to hit appropriate API's to add folders / throw exceptions.  Handling errors will be tough?
        //TODO: error checking, counting total and synced
        foreach ($projects as $project) {
            $this->syncProject($project);
        }

        return $this;
    }

    protected function syncTags(array $tags)
    {
        foreach ($tags as $tag) {
            $this->syncTag($tag);
        }

        return $this;
    }

    protected function syncTasks(array $tasks)
    {
        //TODO: Loop, and use $this->syncTag(Tag $tag) to hit appropriate API's to add folders / throw exceptions.  Handling errors will be tough?
        //TODO: error checking, counting total and synced
        foreach ($tasks as $task) {
            $this->syncTask($task);
        }

        return $this;
    }
}
