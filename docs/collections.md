# Collections

Robo provides task collections as a means of making error detection and recovery easier. When Robo tasks are added to a collection, their execution is deferred until the `$collection->run()` method is called.  When using collections, a Robo script will go through three phases:

1. Determine which tasks will need to be run, and create a collection.
2. Create the necessary tasks and add them to the collection.
3. Run the tasks via $collection->run().

## Basic Collection Example

A simple example is shown below:

``` php
<?php
class RoboFile extends \Robo\Tasks
{
    function myOperation()
    {
        // Determine the value of any variables that will control
        // task execution.
        $baseDir = 'logs';
        
        // Create a collection.
        $collection = $this->collection();
        
        // Add some filesystem tasks to the collection
        $this->taskFileSystemStack()
          ->mkdir($baseDir)
          ->touch("$baseDir/.gitignore")
          ->chgrp($baseDir, 'www-data')
          ->symlink('/var/log/nginx/error.log', '$baseDir/error.log')
          ->addToCollection($collection);
        
        // Add some other tasks.
        $this->taskOther($baseDir)
          ->addToCollection($collection);
        
        // Run all of our tasks.
        $result = $collection->run();
    }
}
?>
```

When `$collection->run()` is executed, all of the tasks in the collection will run, and the final result (a Robo\Result object) will be returned. If any of the tasks fail, then the tasks that follow are skipped.

## Rollbacks

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
        
        // Add a rollback task
        $this->taskFileSystemStack()
          ->rmdir('work')
          ->addAsRollback();

        $this->taskOther('work')
          ->addToCollection($collection);
        
        $result = $collection->run();
    }
}
?>
```

In the example above, if the `mkdir('work')` fails, then neither the rollback task nor the 'taskOther' task will be executed.  If it works, though, then `taskOther()` will also be executed; if `taskOther()` fails, then the rollback task will be run, and the 'work' directory will be removed.

## Temporary Directories

Since the concept of a temporary working directory that is cleaned  up  on failure is a common pattern, Robo provides built-in support for them:

``` php
<?php
class RoboFile extends \Robo\Tasks
{
    function myOperation()
    {
        $collection = $this->collection();
        
        // Create a temporary directory, and fetch its path.
        $work = $this->taskTmpDir()
          ->addToCollection($collection)
          ->getPath();

        $this->taskOther($work)
          ->addToCollection($collection);

        // If all of the tasks succeed, then rename the temporary directory
        // to its final name.
        $this->taskFileSystemStack()
          ->rename($work, 'destination')
          ->addToCollection($collection);
        
        $result = $collection->run();
    }
}
?>
```

In the previous example, the path to the temporary directory is stored in the variable `$work`, and is passed as needed to the parameters of the other tasks as they are added to the collection. After the task collection is run, the temporary directory will be automatically deleted. In the example above, the temporary directory is renamed by the last task in the collection. This allows the working directory to persist; the collection will still attempt to remove the working directory, but no errors will be thrown if it no longer exists in its original location. Following this pattern allows Robo scripts to easily and safely do work that cleans up after itself on failure, without introducing a lot of branching or additional error recovery code.

## Functions, Closures and Methods

### TaskInterface Objects

```php
<?php
  $collection->add(
    $this->taskOther($work)
  );
?>
```

### TaskInterface Lists

```php
<?php
  $collection->add(
    [
      $this->taskOther($work),
      $this->taskYetAnother(),
    ]
  );
?>
```

### Functions

```php
<?php
  $collection->add('mytaskfunction');
?>
```

### Closures

```php
<?php
  $collection->add(
    function() use ($work)
    {
      // do something with $work      
    });
?>
```

### Methods

```php
<?php
  $collection->add([$myobject, 'mymethod']);
?>
```

## Named Tasks

It is also possible to provide names for the tasks added to a collection. This has two primary benefits:

1. Any result data returned from a named task is stored in the Result object under the task name.
2. It is possible for other code to add more tasks before or after any named task.

This feature is useful if you have functions that create task collections, and return them as a function results. The original caller can then use the `$collection->before()` or `$collection->after()` to adjust the set of operations to be performed. One reason this might be done would be to define a base set of operations to perform (e.g. in a deploy), and then apply modifications for other environments (e.g. dev or stage).

```php
<?php
  $collection->add("taskname",
    function() use ($work)
    {
      // do something with $work      
    });
?>
```

Given a collection with named tasks, it is possible to insert more tasks before or after a task of a given name.

```php
<?php
  $collection->after("taskname",
    function() use ($work)
    {
      // do something with $work after "taskname" executes, if it succeeds.    
    });
?>
```

