# TodoMove\Intercessor\Service\Todoist Package Base

* Reader: `TodoMove\Intercessor\Service\Todoist\Reader`
* Writer : `TodoMove\Intercessor\Service\Todoist\Writer`

# How to use

Look at `test.php` for example usage.  The Todoist writer writes Intercessor objects to Todoist, and can sync fully an Intercessor reader

# Notes

* Projects must be linked to a folder for this to indent your information correctly - `$project->folder($folder);`
* Folders must be linked to projects
* You must sync Tags, then Folders, then Tasks.  Projects are synced when you sync a folder 