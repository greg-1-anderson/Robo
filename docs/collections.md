# Collections

Robo provides task collections as a means of making error detection and recovery easier. When Robo tasks are added to a collection, their execution is deferred until the `$collection->run()` method is called.  When using collections, a Robo script will go through three phases:

1. Determine which tasks will need to be run, and create a collection.
  - Assign values to variables.
  - Do not alter the state of the system.
2. Create the necessary tasks and add them to the collection.
  - Pass variables calculated in the first phase to task parameters.
3. Run the tasks via `$collection->run()`.
  - Check and report errors once after `run()` returns.

Following this pattern will keep your code linear and easy to understand.

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

### Rollback Tasks

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
          ->addAsRollback($collection);

        $this->taskOther('work')
          ->addToCollection($collection);
        
        $result = $collection->run();
    }
}
?>
```

In the example above, if the `mkdir('work')` fails, then neither the rollback task nor the 'taskOther' task will be executed.  If it works, though, then `taskOther()` will also be executed; if `taskOther()` fails, then the rollback task will be run, and the 'work' directory will be removed.

### Rollback Methods

Any task may also implement \Robo\Contract\RollbackInterface; if this is done, then its `rollback()` method will be called if the task is `run()` on a collection that later fails.

## Completions

In addition to rollbacks, Robo collections also support completions.  Completions run whenever their collection completes or rolls back, but only if all of the tasks that come before it succeed.

Use `addAsCompletion($collection)` in place of `addAsRollback($collection)`, or implement \Robo\Contract\CompletionInterface. Completions otherwise work exactly like rollbacks.

## Temporary Objects

Since the concept of temporary objects that are cleaned up  on failure is a common pattern, Robo provides built-in support for them. Temporary directories and files are provided out of the box; other kinds of temporary objects can be easily created using the Temporary global collection.

### Temporary Directories

It is recommended that operations that perform multiple filesystem operations should, whenever possible, do most of their work in a temporary directory that is moved into its final location near the end of the task collection. This makes it easy to clean up after an aborted task.

If you use a temporary directory created by `$this->taskTmpDir()`, it will automatically be removed when the collection completes or rolls back. Move the temporary directory to another location to prevent its deletion.

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

### Temporary Files

Robo also provides an API for creating temporary files. They may be created via `$this->taskTmpFile()`; they are used exactly like `$this->taskWrite()`, except they are given a random name on creation, and are deleted when their collection completes.  If they are not added to a collection, then they are deleted when the script terminates.

### The Temporary Global Collection

Robo maintains a global collection named Temporary that is used to keep track of object temporary objects that are created, but not added to any collection.  These objects are automatically removed when the script terminates.

To make a task behave like a temporary object, extend \Robo\Contract\CompletionInterface, and wrap the task object using `Temporary::wrap()` in its loadShortcuts factory function. Then, the task's `complete()` method will be called if its collection completes or rolls back.

The taskTmpFile factory function looks like this:

``` php
<?php
    protected function taskTmpFile($filename = 'tmp', $extension = '', $baseDir = '', $includeRandomPart = true)
    {
        return Temporary::wrap(new TmpFile($filename, $extension, $baseDir, $includeRandomPart));
    }
?>
```

The wrapped task will be added to the Temporary global collection if its `run()` method is called. Robo will automatically unwrap the object if it is added to some other collection, in which case it will be cleaned up if it is `run()` on that collection, once it completes or rolls back.

Note, therefore, that a Temporary object is simply a Completion that is also added to the Temporary global collection.

## Adding Tasks to Collections

In the previous example, tasks were added to collections using the `addToCollection($collection)` method available in BaseTask; this, however, is little more than a convenience wrapper to `$collection->add()`. The `add()` method accepts a variety of different parameter types, making it convenient to add operations to collections in a number of different ways.

The following types can be added to a collection:

- A TaskInterface
- An array of TaskInterfaces
- A Callable object
  - A function name (string)
  - A closure (inline function)
  - A method reference (array with object and method name)
  
Examples of all of these appear below.

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

This feature is useful if you have functions that create task collections, and return them as a function results. The original caller can then use the `$collection->before()` or `$collection->after()` to insert sequenced tasks into the set of operations to be performed. One reason this might be done would be to define a base set of operations to perform (e.g. in a deploy), and then apply modifications for other environments (e.g. dev or stage).

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

```php
<?php
  $collection->before("taskname",
    function() use ($work)
    {
      // do something with $work before "taskname" executes.    
    });
?>
```

It is recommended that named tasks be avoided unless specifically needed.

