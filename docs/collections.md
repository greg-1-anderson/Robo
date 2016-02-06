# Collections

Robo provides task collections as a means of making error detection and recovery easier. When Robo tasks are added to a collection, their execution is deferred until all of the tasks to be executed have also been added to the collection, and `$collection->run()` is called. A simple example is shown below:

``` php
<?php
class RoboFile extends \Robo\Tasks
{
    function myOperation()
    {
        $collection = $this->collection();
        
        $this->taskFileSystemStack()
          ->mkdir('logs')
          ->touch('logs/.gitignore')
          ->chgrp('logs', 'www-data')
          ->symlink('/var/log/nginx/error.log', 'logs/error.log')
          ->addToCollection($collection);
        
        $this->taskOther()
          ->addToCollection($collection);
        
        $result = $collection->run();
    }
}
?>
```

When `$collection->run()` is executed, all of the tasks in the collection will run, and the final result (a Robo\Result object) will be returned. If any of the tasks fail, then the tasks that follow are skipped.

It is also possible to add rollback tasks to a collection.  A rollback task is executed if all of the tasks that come before it succeed, and at least one of the tasks that come after it fails.  If all tasks succeed, then no rollback tasks are executed.

Rollback tasks can be used to clean up after failures, so the state of the system does not change when execution is interrupted by an error. An example of this is shown below:

``` php
<?php
class RoboFile extends \Robo\Tasks
{
    function myOperation()
    {
        $collection = $this->collection();
        
        $this->taskFileSystemStack()
          ->mkdir('work')
          ->addToCollection($collection);
          
        $this->rollback(
          $this->taskFileSystemStack()
            ->rmdir('work')
        );

        $this->taskOther()
          ->destination('work')
          ->addToCollection($collection);
        
        $result = $collection->run();
    }
}
?>
```

Since the concept of a temporary working directory that is cleaned  up  on failure is a common pattern, Robo provides built-in support for them:

``` php
<?php
class RoboFile extends \Robo\Tasks
{
    function myOperation()
    {
        $collection = $this->collection();
        
        $work = $this->taskTmpDir()
          ->addToCollection($collection)
          ->getPath();

        $this->taskOther()
          ->destination($work)
          ->addToCollection($collection);

        $this->taskFileSystemStack()
          ->rename($work, 'destination')
          ->addToCollection($collection);
        
        $result = $collection->run();
    }
}
?>
```

In the previous example, the path to the temporary directory is stored in the variable `$work`, and is passed as needed to the parameters of the other tasks as they are added to the collection. After the task collection is run, the temporary directory will be automatically deleted. In the example above, the temporary directory is renamed at the very end, which allows its contents to presist. The task collection will attempt to delete the temporary directory under its original name; if the directory no longer exists as the end  of execution, there are no ill effects.

It is also possible to insert arbitrary function and method calls into the collection:

```php
<?php
  $collection->add(
    function() use ($work)
    {
      // do something with $work      
    });
?>
```
