<?php

namespace TodoMove\Service\Todoist;

use GuzzleHttp\Client;
use TodoMove\Intercessor\Project;
use TodoMove\Intercessor\ProjectFolder;
use TodoMove\Intercessor\Repeat;
use TodoMove\Intercessor\Tag;
use TodoMove\Intercessor\Tags;
use TodoMove\Intercessor\Task;

class Reader extends \TodoMove\Intercessor\Service\AbstractReader
{
    public $client;
    private $token;
    private $isPremium = false;
    private $all = [];

    private $projectIds = []; // Map todoist-id's to projects
    private $labelIds = []; // Map todoist-id's to tags
    private $taskIds = []; // Map todoist-id's to tasks

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

        $this->all = $this->readAll(); // Reads all sync data from Todoist

        $this->parseTags(); // Must be first as Projects/Tasks utilise them
        $this->parseProjects(); // This created folders _and_ projects
        $this->parseTasks(); // This attaches tasks to projects
        $this->parseNotes();

        /*
        $this->parseFolders(); // Not needed for Todoist as folders are actually projects
        TODO: Though if we do this and we have a project with indent=1, with no subprojects, then in some services we won't be able to assign tasks as it will be a folder not a project

        */
    }


    private function isPremium()
    {
        try {
            $response = json_decode($this->client->get('', [
                'form_params' => [
                    'token' => $this->token,
                    'sync_token' => '*',
                    'resource_types' => json_encode(['user'])
                ],
            ])->getBody(), true);
        } catch (Exception $e) {
            $this->isPremium = false;
            return $this->isPremium;
        }

        $this->isPremium = $response['user']['is_premium'];

        return $this->isPremium;
    }


    private function readAll()
    {
        $result = $this->client->get('', [
            'query' => [
                'token' => $this->token,
                'sync_token' => '*',
                'resource_types' => json_encode(['labels', 'projects', 'items', 'notes']),
            ],
        ]);

        $response = json_decode($result->getBody(), true);

        return $response;
    }

    /**
     * @return $this
     */
    public function parseTags()
    {
        foreach ($this->all['labels'] as $label) {
            if ($label['is_deleted']) {
                continue;
            }
            $tag = new Tag($label['name']);
            $this->addTag($tag);
            $this->labelIds[$label['id']] = $tag;
        }
        return $this;
    }

    /**
     * @return $this
     */
    protected function parseProjects()
    {
        // TODO: Add \TodoMove\Intercessor\Project's to the projects array, keyed by id: $this->addProject($project);
        usort($this->all['projects'], function ($a, $b) {
            if ($a['item_order'] == $b['item_order']) {
                return 0;
            }

            return ($a['item_order'] < $b['item_order']) ? -1 : 1;
        });

        $lastFolder = null;
        // We will only support indentation one level deep to keep a folder -> project relationship (projects in a folder)
        foreach ($this->all['projects'] as $project) {
            if ($project['is_deleted']) {
                continue;
            }

            if ($project['indent'] == 1) { // Folder
                $folder = new ProjectFolder($project['name']);
                $this->addFolder($folder);
                $lastFolder = $folder;
            }

            $intercessorProject = new Project($project['name']);
            $this->addProject($intercessorProject);
            $this->projectIds[$project['id']] = $intercessorProject;

            if (!is_null($lastFolder)) {
                $lastFolder->project($intercessorProject);
                $intercessorProject->folder($lastFolder);
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function parseFolders()
    {
        // Not needed as this is done in parseProjects
        return $this;
    }

    /**
     * @return $this
     */
    public function parseTasks()
    {
        foreach ($this->all['items'] as $item) {
            if ($item['is_deleted'] || $item['checked']) {
                continue;
            }

            if (!array_key_exists($item['project_id'], $this->projectIds)) {
                continue;
            }

            $task = new Task($item['content']);
            $task->project($this->projectIds[$item['project_id']]);

            $tags = new Tags();

            foreach ($item['labels'] as $labelId) {
                $tags->add($this->labelIds[$labelId]);
            }

            $task->tags($tags);

            if ($item['priority'] < 4) {
                $task->flagged(true);
            }

            $task->created(new \DateTime($item['date_added']));
            if (!empty($item['due_date_utc'])) {
                $task->due(new \DateTime($item['due_date_utc']));
            }

            // Recurrence
            if (!empty($item['date_string']) && strpos(strtolower($item['date_string']), 'every ') === 0) {
                $repeat = $this->createRepeat($item['date_string']);
                if (!is_null($repeat)) {
                    $task->repeat($repeat);
                }
            }

            $this->addTask($task);
            $this->taskIds[$item['id']] = $task;
        }

        return $this;
    }

    // TODO: Don't be rudimentary
    public function createRepeat($dateString) // 'every month', 'every 3 days', 'every 20th', 'every month', 'every sat', 'every day 6am'
    {
        $dateString = preg_replace('/every /', '', strtolower($dateString));
        $parts = explode(' ', $dateString); // Sunday at 9pm
        $repeat = new Repeat();
        switch ($parts[0]) {
            case 'month':
                return $repeat->monthly();
            case 'week':
                return $repeat->weekly();
            case 'day':
                return $repeat->daily();
            case 'year':
                return $repeat->yearly();

            //TODO: Support other languages
            //TODO: Tidy this up
            case 'monday':
            case 'mon':
            case 'tuesday':
            case 'tue':
            case 'wednesday':
            case 'wed':
            case 'thursday':
            case 'thu':
            case 'friday':
            case 'fri':
            case 'saturday':
            case 'sat':
            case 'sunday':
            case 'sun':
                return $repeat->type(Repeat::WEEK); // We don't need to set the date as such, as the due date will be set to the next Saturday so it should repeat as expected
        }

        // 20th, 14th, 2nd, 1st, 11th (every month on these dates)
        $monthlyOnDate = preg_match('/[0-9]{1,2}(nd|th|st)/', $dateString, $matches);
        if ($monthlyOnDate) {
            return $repeat->type(Repeat::MONTH);
        }

        // every X [days|weeks|months|year]
        $supported = preg_match('/(?<interval>[0-9]+) (?<type>[a-z]+)/', $dateString, $matches);
        if (!$supported) {
            throw new \Exception('Unsupported repeat string: ' . $dateString);
        }

        if (empty($matches['interval']) || !is_numeric($matches['interval'])) {
            throw new \Exception('Invalid interval in repeat: "' . $matches['interval'] . '": ' . var_export($matches));
        }

        switch ($matches['type']) {
            case 'days':
                $repeat->interval($matches['interval']);
                return $repeat->type(Repeat::DAY);
            case 'weeks':
                $repeat->interval($matches['interval']);
                return $repeat->type(Repeat::WEEK);
            case 'months':
                $repeat->interval($matches['interval']);
                return $repeat->type(Repeat::MONTH);
            case 'years':
                $repeat->interval($matches['interval']);
                return $repeat->type(Repeat::YEAR);
            default:
                throw new Exception('Unsupported date repeat: ' . $dateString);
        }

        return null;
    }

    public function parseNotes()
    {
        foreach ($this->all['notes'] as $note)
        {
            $currentNotes = $this->taskIds[$note['item_id']]->notes();
            $this->taskIds[$note['item_id']]->notes($currentNotes . $note['content']);
        }
    }
}
