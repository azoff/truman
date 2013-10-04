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
- __Deduplication__: Identical Bucks are guaranteed to execute one at a time throughout the distributed system.
  + It's about as good as a guarantee as any, but don't sue me over it; see the following section for details.
- __Resiliant__: Desks default to automatically reap dead drawers, addressing any orphaned processes.
  + PHP memory leaks are simply avoided by allowing Drawers to die gracefully. Still working on Desks though...
- __Efficiency__: Using PHP's [Socket Extension][8] for network communication means the guarantees of TCP without the latency of HTTP.
  + Desks and Drawers also do inter-process communication via standard file streams (e.g. STDOUT).
- __Visibility__: Desk callbacks expose all parts of a Buck's lifecycle. All classes are accessible and fully documented.
- __Configurable__: Desks can be configured to spawn any number of Drawers with any set of local PHP includes.
  + Implementors can send Notifications to Desks to force their Drawers to do hot code replacements.
- __Monitorable__: Loggers document every part of a Buck's lifecycle to local log files.
  + Monitors can be written to passively aggregate network activity and replay in the case of network failure.


Distributed Deduplication
-------------------------
Deduplication over the network is one of the core tenets guiding the design of Truman's network topography. Any client
should be able to add a Buck to the distributed queue with the guarantee that it will not be executed in parallel on
some other Desk. By enforcing this guarantee, a Buck method can be written in a way that is thread-safe, and unlikely to
conflict with similar jobs. To accomplish distributed deduplication, Truman relies on a couple preconditions:

- Desks maintain a __priority queue__ of __unique__ Bucks
- Clients __consistently__ pair Bucks with __the same__ Desk in the network

With these preconditions in place, the network may accomplish distributed deduplication. In short, deduplication happens
locally on each Desk, but Bucks are consistently sent to their paired Desks. Hence, any given Buck may only run once at
a time, on a unique Desk in the network. For example, consider the typical lifecycle of a Buck in the network:

- The user creates a Buck, to be executed by a Desk somewhere in the network
- The user then instantiates a Client
- The Client sends a Notification, containing an encoded copy of itself, to all connected Desks
  - The Desks receive the Notification and enqueue it with the highest priority possible (to block other Bucks)
  - The Desks then compare the Client encoded in the Notification to their current Client representation
  - If the encoded Client is newer and different than the Desk's Client, then the Desk's Client is replaced
- The Client routes the Buck to its paired Desk in the network.
- Upon receiving the Buck over the network, the paired Desk determines whether to enqueue or drop the Buck.
  - If the Desk is tracking an identical Buck, the Buck is dropped (local deduplication).
- The Desk dequeues and reverifies the Buck, using its internal copy of the Client to check for ownership.
  - If the Desk's Client pairs the Buck to another Desk, the Desk reroutes the Buck using the Client.
  - If the Desk's Client pairs the Buck to the local Desk, the Buck is finally executed.

Assuming that Desks are not rapidly scaling up and down, distributed deduplication is theoretically guaranteed. However,
if the network topography changes rapidly, it's possible to have situations where a Buck is executing while it's identical
twin finds its way to a new Desk. This trade-off is one that implies scaling up and down should be done sparingly, or in
times of low traffic. Eventually, there are plans to add a "delay" notification, which will pause all Desks for a
predetermined amount of time while the network is scaled up or down.

If you would like to contribute to this algorithm, please feel free to [create an issue][11] or submit a pull request.

Getting Started
---------------
The easiest way to get started with Truman is to include [the autoloader][14] and use the [convenience class][9]. Here
is an [example client and server][10] running on the same box (the default settings):

__server.php__
```php
require_once '/path/to/TRUMAN_HOME/autoload.php';

// stop the script, listen, and delegate incoming jobs
truman\Truman::listen();
```

__client.php__
```php
require_once '/path/to/TRUMAN_HOME/autoload.php';

// calls sleep(1) in some other PHP process
truman\Truman::enqueue('sleep', [1]);
```

__/tmp/truman.log__
```sh
# TIMESTAMP       | ACTOR  | ID, PID, OR SOCKET               | EVENT             | DETAILS
  1380825933.5260 | DESK   | 0.0.0.0:12345                    | INIT              | [42888,42889,42890]
  1380825933.5261 | DESK   | 0.0.0.0:12345                    | START             |
  1380825933.5745 | DRAWER | 42890                            | INIT              |
  1380825933.5783 | DRAWER | 42888                            | INIT              |
  1380825933.5835 | DRAWER | 42889                            | INIT              |
  1380825941.6286 | BUCK   | 0769b54e1144b8c807c02a51645badca | INIT              | {"callable":"sleep","args":[1],"options":{"priority":2048,"channel":"default","logger_options":[],"context":null,"memory_limit":134217728,"time_limit":60}}
  1380825941.6479 | CLIENT | 1e6c4c2ca62794419bd64033dc094b97 | INIT              | {"desks":["0:12345"],"timestamp":"1380825941.6479"}
  1380825941.6490 | NOTIF  | b166ad894d123765ccd2114487fde158 | INIT              | {"type":"DESK_CLIENT_UPDATE","notice":"YToxOntpOjA7YTozOntzOjQ6InBvcnQiO2k6MTIzNDU7czo0OiJob3N0IjtpOjA7czo4OiJjaGFubmVscyI7YToxOntpOjA7czo3OiJkZWZhdWx0Ijt9fX0=@1380825941.6479","buck":{"callable":"__NOOP__","args":[],"options":{"priority":9223372036854775807,"channel":"default","logger_options":[],"context":null,"memory_limit":134217728,"time_limit":60}}}
  1380825941.6491 | CLIENT | 1e6c4c2ca62794419bd64033dc094b97 | NOTIFY_START      | "YToxOntpOjA7YTozOntzOjQ6InBvcnQiO2k6MTIzNDU7czo0OiJob3N0IjtpOjA7czo4OiJjaGFubmVscyI7YToxOntpOjA7czo3OiJkZWZhdWx0Ijt9fX0=@1380825941.6479"
  1380825941.6761 | NOTIF  | b166ad894d123765ccd2114487fde158 | SEND_START        | "127.0.0.1:12345"
  1380825941.6763 | NOTIF  | b166ad894d123765ccd2114487fde158 | SEND_COMPLETE     | "127.0.0.1:12345"
  1380825941.6764 | CLIENT | 1e6c4c2ca62794419bd64033dc094b97 | NOTIFY_COMPLETE   |
  1380825941.6765 | BUCK   | 0769b54e1144b8c807c02a51645badca | SEND_START        | "127.0.0.1:12345"
  1380825941.6766 | BUCK   | 0769b54e1144b8c807c02a51645badca | SEND_COMPLETE     | "127.0.0.1:12345"
  1380825941.6794 | NOTIF  | b166ad894d123765ccd2114487fde158 | RECEIVED          | "0.0.0.0:12345"
  1380825941.6861 | NOTIF  | b166ad894d123765ccd2114487fde158 | ENQUEUED          | 9223372036854775807
  1380825941.6884 | CLIENT | 1e6c4c2ca62794419bd64033dc094b97 | INIT              | {"desks":["0:12345"],"timestamp":"1380825941.6479"}
  1380825941.6885 | DESK   | 0.0.0.0:12345                    | CLIENT_UPDATE     | "1e6c4c2ca62794419bd64033dc094b97"
  1380825941.6885 | NOTIF  | b166ad894d123765ccd2114487fde158 | DEQUEUED          |
  1380825941.6889 | BUCK   | 0769b54e1144b8c807c02a51645badca | RECEIVED          | "0.0.0.0:12345"
  1380825941.6961 | BUCK   | 0769b54e1144b8c807c02a51645badca | ENQUEUED          | 2048
  1380825941.6962 | BUCK   | 0769b54e1144b8c807c02a51645badca | DELEGATE_START    | 42888
  1380825941.6963 | BUCK   | 0769b54e1144b8c807c02a51645badca | DEQUEUED          |
  1380825941.7168 | BUCK   | 0769b54e1144b8c807c02a51645badca | EXECUTE_START     | 42888
  1380825942.7251 | BUCK   | 0769b54e1144b8c807c02a51645badca | EXECUTE_COMPLETE  | {"success":true,"buck":"0769b54e1144b8c807c02a51645badca","details":{"pid":42888,"error":null,"retval":0,"memory":524288,"output":null,"runtime":1.0069179534912,"exception":null,"memory_base":262144}}
  1380825942.7371 | BUCK   | 0769b54e1144b8c807c02a51645badca | DELEGATE_COMPLETE | 42888
```

__Note__: There are other actors and events that one could see in the log file. However, for the most part, this is the
standard interaction between a Client and Desk.

Documentation
--------------
Truman is obviously much more configurable than the example and convenience class might lead you to believe. While
documentation does not currently exist in web form, all classes are fully documented using standard PHPDoc notation. If
you want utilize the Framework to a degree beyond the default case, I highly recommend starting with the [convenience
class][9] and working your way down into the framework internals. The [tests][13] are also a great reference for
integration between classes.

License
-------
Coming soon...

Contribution
------------
Want to complain about Truman? [Create a new issue][11]!

Want to make Truman better? [Fork the repository][12] and submit a pull request!

TODO
----
- LoadTest needs to spawn Desks as their own processes and read the log file instead
  + Right now the desks are spawned in the LoadTest monitor, effecting the true throughput of the system
- What happens when a socket fails?
- What if a Drawer never returns?
- Autoscaling drawers?
  + Maybe base it off of an effective throughput in constructor?
- Autoscaling desks?
  + Should probably send "delay all" notification to existing desks (on scale up or down) to protect guarantee.

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
[11]:https://github.com/azoff/truman/issues
[12]:https://github.com/azoff/truman/fork
[13]:/tests
[14]:/autoload.php
