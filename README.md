Truman
======
A pure-PHP framework to defer method calls

The Problem
-----------
In large-scale web applications, users have better experiences when pages load quickly. On occasion, however,
users may initiate processes that take a bit of time to complete. If the front-end web servers are also used
to handle these long-running processes, the server will block and users will suffer. In PHP, this problem is
exacerbated because of [the way requests are processed by the SAPI][1]. Scripts run in a single thread
of execution, blocking on any long-running processes encountered during the execution lifecycle. Given enough
blocked threads, the web server will eventually starve from lack of resources (this is bad).

To work around this issue, most PHP developers resort to using [message passing systems][2] or the less general
[job queue][3] implementations. These implementations are meant to be generic, and are typically written in languages
other than PHP. Some services even exist (such as [Amazon SQS][4]) to serve job queues which over an HTTP interface.
The problem with almost all of these offerings is that, by their very nature, they are black boxes. For the average use
case, this isn't really a problem. However, as the job volume increases, increased visibility usually outweighs the
initial convenience they afford. Having a free, open-source, vanilla-PHP offering seems like the only way to address
this concern. As you'll see, this doesn't come without it's costs. PHP was [never designed to be used in this fashion][5],
so a lot of the solution is still a work in progress.

The Solution
------------
Truman is a pure-PHP framework that can be used to defer method calls from one PHP process to another. The name, and its
internal metaphors, come from the [phrase popularized by President Harry Truman][6], "The buck stops here". Indeed,
[passing the buck][7] is an apt metaphor for how these type of systems work. Specifically, Truman (the framework) resembles
a [message passing system][2] in that it passes serialized messages to a distributed worker network. It also resembles
a [job queue][3] in that each worker maintains a priority queue of methods to execute. To make this all possible, several
PHP classes work together to communicate and execute deferred methods:

- __Bucks__: The implementation of a deferred method call. Bucks represent work to do on another PHP process.
- __Drawers__: The innards of PHP processes that receive and execute queued Bucks. They are spawned and managed by Desks.
- __Desks__: Monitors that listen for inbound Bucks over the network. Desks enqueue, prioritize, and delegate Bucks to Drawers.
- __Clients__: Clients choose which Bucks go to which Desks, and send the Bucks over the network.
- __Loggers__: Loggers note every part of the Buck lifecycle; their output can be consumed and reused to recover from queue failure.
- __Notifications__: Special types of Bucks used for administrative purposes in the Truman network

Truman is still a work in progress, and dealing with PHP probably means its going to stay that way for a while. That
being said, here are some of the features you can expect to inherit should you decide to use Truman:

- __Priority__: Bucks on a Desk can be more important than others. Priority allows the implementer explicitly define that.
- __Channels__: The implementer can designate Bucks to run within a channel, or cluster of Desks, allowing for server specialization.
- __Contexts__: The implementer can name a Buck's context, allowing for child Bucks to inherit their parent's context.
- __Limits__: The implementer can explicitly define memory or timing limits on execution.
- __Deduplication__: Identical Bucks are guaranteed* to execute one at a time throughout the distributed system.
  + * It's about as good as a guarantee as any, but don't sue me over it; see the following section for details.
- __Resiliant__: Desks default to automatically reap dead drawers, addressing any orphaned processes.
  + PHP memory leaks are simply avoided by allowing Drawers to die gracefully. Still working on Desks though...
- __Efficiency__: Using PHP's [Socket Extension][8] for network communication means the guarantees of TCP without the latency of HTTP.
  + Desks and Drawers also do inter-process communication via standard file streams (e.g. STDOUT).
- __Visibility__: Desk callbacks expose all parts of a Buck's lifecycle. All classes are accessible and fully documented.
- __Configurable__: Desks can be configured to spawn any number of Drawers with any set of local PHP includes.
  + Implementors can send Notifications to Desks to force their Drawers to do hot code replacements.
- __Monitorable__: Loggers document every part of a Buck's lifecycle to local log files.
  + Monitors can be written to passively aggregate network activity and replay in the case of network failure.


Getting Started
---------------
The easiest way to get started with Truman is to include the autoloader and use the [convenience class][9]. Here is an
[example client and server][10] running on the same box (the default settings):

__server.php__
```php
require_once '/path/to/TRUMAN_HOME/autoload.php';

// stop the script, listen, and delegate incoming jobs
truman\Truman::listen();
```

__client.php__
```php
require_once '/path/to/TRUMAN_HOME/autoload.php';

// enqueue a job to sleep for 1 second
truman\Truman::enqueue('sleep', [1]);
```

__/tmp/truman.log__
```sh
# TIMESTAMP       | ACTOR  | ID, PID, OR SOCKET               | EVENT             | DETAILS
  1380319965.4672 | DESK   | 0.0.0.0:12345                    | INIT              | [9557,9558,9559]
# Desk starts, listens on port 12345, and spawns three drawers...
  1380319965.4673 | DESK   | 0.0.0.0:12345                    | START             |
  1380319965.5196 | DRAWER | 9559                             | INIT              |
  1380319965.5234 | DRAWER | 9557                             | INIT              |
  1380319965.5261 | DRAWER | 9558                             | INIT              |
# Buck is created in client.php...
  1380319968.8462 | BUCK   | 0769b54e1144b8c807c02a51645badca | INIT              | {"callable":"sleep","args":[1],"options":[]}
# Client starts, creates a notification to inform desks about network topography...
  1380319968.8485 | CLIENT | bac1f5c9c06f70f46ea7542fb95290d2 | INIT              | {"desks":["127.0.0.1:12345"],"timestamp":"1380319968.8485"}
  1380319968.8557 | NOTIF  | 577aef2845bdaeeddfb5bbb16d446910 | INIT              | {"type":0,"notice":"YToxOntpOjA7YTozOntzOjQ6InBvcnQiO2k6MTIzNDU7czo0OiJob3N0IjtzOjk6IjEyNy4wLjAuMSI7czo4OiJjaGFubmVscyI7YToxOntpOjA7czo3OiJkZWZhdWx0Ijt9fX0=@1380319968.8485","options":{"priority":9223372036854775807}}
  1380319968.8558 | CLIENT | bac1f5c9c06f70f46ea7542fb95290d2 | NOTIFY_START      | "YToxOntpOjA7YTozOntzOjQ6InBvcnQiO2k6MTIzNDU7czo0OiJob3N0IjtzOjk6IjEyNy4wLjAuMSI7czo4OiJjaGFubmVscyI7YToxOntpOjA7czo3OiJkZWZhdWx0Ijt9fX0=@1380319968.8485"
  1380319968.9280 | NOTIF  | 577aef2845bdaeeddfb5bbb16d446910 | SEND_START        | "127.0.0.1:12345"
  1380319968.9282 | NOTIF  | 577aef2845bdaeeddfb5bbb16d446910 | SEND_COMPLETE     | "127.0.0.1:12345"
  1380319968.9283 | CLIENT | bac1f5c9c06f70f46ea7542fb95290d2 | NOTIFY_COMPLETE   |
# Client sends the Buck to the drawer
  1380319968.9283 | BUCK   | 0769b54e1144b8c807c02a51645badca | SEND_START        | "127.0.0.1:12345"
  1380319968.9284 | BUCK   | 0769b54e1144b8c807c02a51645badca | SEND_COMPLETE     | "127.0.0.1:12345"
# Notification is received by the Desk and delegated to Drawer PID 9557
  1380319968.9568 | NOTIF  | 577aef2845bdaeeddfb5bbb16d446910 | RECEIVED          | "0.0.0.0:12345"
  1380319968.9569 | NOTIF  | 577aef2845bdaeeddfb5bbb16d446910 | ENQUEUED          | 9223372036854775807
  1380319968.9796 | CLIENT | bac1f5c9c06f70f46ea7542fb95290d2 | INIT              | {"desks":["127.0.0.1:12345"],"timestamp":"1380319968.8485"}
  1380319968.9797 | DESK   | 0.0.0.0:12345                    | CLIENT_UPDATE     | "bac1f5c9c06f70f46ea7542fb95290d2"
  1380319968.9800 | NOTIF  | 577aef2845bdaeeddfb5bbb16d446910 | DELEGATE_START    | 9557
  1380319968.9801 | NOTIF  | 577aef2845bdaeeddfb5bbb16d446910 | DEQUEUED          |
# Buck is received by Desk and delegated to Drawer PID 9558
  1380319968.9805 | BUCK   | 0769b54e1144b8c807c02a51645badca | RECEIVED          | "0.0.0.0:12345"
  1380319968.9805 | BUCK   | 0769b54e1144b8c807c02a51645badca | ENQUEUED          | 2048
  1380319968.9806 | BUCK   | 0769b54e1144b8c807c02a51645badca | DELEGATE_START    | 9558
  1380319968.9807 | BUCK   | 0769b54e1144b8c807c02a51645badca | DEQUEUED          |
# Drawer starts work on Buck
  1380319968.9910 | BUCK   | 0769b54e1144b8c807c02a51645badca | EXECUTE_START     | 9558
# Drawer starts work on Notification
  1380319968.9928 | NOTIF  | 577aef2845bdaeeddfb5bbb16d446910 | EXECUTE_START     | 9557
# Drawer finishes work on Notification and returns result to Desk
  1380319968.9940 | NOTIF  | 577aef2845bdaeeddfb5bbb16d446910 | EXECUTE_COMPLETE  | {"pid":9557,"runtime":4.2915344238281e-5,"memory_base":262144,"retval":"YToxOntpOjA7YTozOntzOjQ6InBvcnQiO2k6MTIzNDU7czo0OiJob3N0IjtzOjk6IjEyNy4wLjAuMSI7czo4OiJjaGFubmVscyI7YToxOntpOjA7czo3OiJkZWZhdWx0Ijt9fX0=@1380319968.8485","memory":262144}
  1380319969.0005 | NOTIF  | 577aef2845bdaeeddfb5bbb16d446910 | DELEGATE_COMPLETE | 9557
# Drawer finishes work on Buck and returns result to Desk
  1380319969.9936 | BUCK   | 0769b54e1144b8c807c02a51645badca | EXECUTE_COMPLETE  | {"pid":9558,"runtime":1.0010588169098,"memory_base":262144,"retval":0,"memory":262144}
  1380319970.0113 | BUCK   | 0769b54e1144b8c807c02a51645badca | DELEGATE_COMPLETE | 9558
```

Documentation
--------------
Truman is obviously much more configurable than the example and convenience class might lead you to believe. While
documentation does not currently exist in web form, all classes are fully documented using standard PHPDoc notation. If
you want utilize the Framework to a degree beyond the default case, I highly recommend starting with the [convenience
class][9] and working your way down into the Framework internals.

License
-------
Coming soon...

Contribution
------------
Want to complain about Truman? [Create a new issue][11]!

Want to make Truman better? [Fork the repository][12] and submit a pull request!

TODO
----
- Add context to buck INIT log (or just use final options)
  - Add a Notification that allows Desks to ignore/unignore bucks by context
  - Update log in README
- Truman tests
- What happens when a socket fails?
- Document functions
- Allow clients to start without notifying (dangerous)
- Autoscaling drawers?
- Ensure pcntl and sockets are installed on target dist

[1]:http://abhinavsingh.com/blog/2008/11/how-does-php-echos-a-hello-world-behind-the-scene/
[2]:http://en.wikipedia.org/wiki/Message_passing
[3]:http://en.wikipedia.org/wiki/Job_queue
[4]:http://aws.amazon.com/sqs
[5]:http://software-gunslinger.tumblr.com/post/47131406821/php-is-meant-to-die
[6]:http://en.wiktionary.org/wiki/the_buck_stops_here
[7]:http://en.wikipedia.org/wiki/Buck_passing
[8]:http://php.net/manual/en/book.sockets.php
[9]:/src/truman/Truman.php
[10]:/example
[11]:issues
[12]:fork