<?php

declare(strict_types=1);

namespace Tests;

use Exan\Eventer\Eventer;
use Exan\Eventer\EventInterface;
use Exan\Eventer\Exceptions\InvalidEventException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use stdClass;

class EventerTest extends TestCase
{
    #[Test]
    public function it_emits_an_event()
    {
        $eventer = new Eventer();

        $state = (object) ['success' => false];

        $event = new class ($state) implements EventInterface {
            public function __construct(private stdClass &$state)
            {
            }

            public static function getEventName(): string
            {
                return '::event name::';
            }

            public function filter(): bool
            {
                return true;
            }

            public function execute(): void
            {
                $this->state->success = true;
            }
        };

        $eventer->register($event::class);

        $eventer->emit('::event name::', [$state]);

        $this->assertTrue($state->success);
    }

    #[Test]
    public function it_filters_events()
    {
        $eventer = new Eventer();

        $state = (object) ['runs' => []];

        $event = new class ($state, true) implements EventInterface {
            public function __construct(private stdClass &$state, private bool $shouldRun)
            {
            }

            public static function getEventName(): string
            {
                return '::event name::';
            }

            public function filter(): bool
            {
                return $this->shouldRun;
            }

            public function execute(): void
            {
                $this->state->runs[] = $this->shouldRun;
            }
        };

        $eventer->register($event::class);

        $eventer->emit('::event name::', [$state, true]);
        $eventer->emit('::event name::', [$state, false]);

        $this->assertEquals([true], $state->runs);
    }

    #[Test]
    public function it_does_not_clear_filtered_events_after_non_matched_filters()
    {
        $eventer = new Eventer();

        $state = (object) ['runs' => []];

        $event = new class ($state, true) implements EventInterface {
            public function __construct(private stdClass &$state, private bool $shouldRun)
            {
            }

            public static function getEventName(): string
            {
                return '::event name::';
            }

            public function filter(): bool
            {
                return $this->shouldRun;
            }

            public function execute(): void
            {
                $this->state->runs[] = $this->shouldRun;
            }
        };

        $eventer->registerOnce($event::class);

        $eventer->emit('::event name::', [$state, false]);
        $eventer->emit('::event name::', [$state, true]);
        $eventer->emit('::event name::', [$state, true]);

        $this->assertEquals([true], $state->runs);
    }

    #[Test]
    public function it_does_not_emit_on_events_with_a_different_name()
    {
        $eventer = new Eventer();

        $state = (object) ['runs' => []];

        $shouldRunEvent = new class ($state) implements EventInterface {
            public function __construct(private stdClass &$state)
            {
            }

            public static function getEventName(): string
            {
                return '::event name, should run::';
            }

            public function filter(): bool
            {
                return true;
            }

            public function execute(): void
            {
                $this->state->runs[] = self::getEventName();
            }
        };

        $shouldNotRunEvent = new class ($state) implements EventInterface {
            public function __construct(private stdClass &$state)
            {
            }

            public static function getEventName(): string
            {
                return '::event name, should NOT run::';
            }

            public function filter(): bool
            {
                return true;
            }

            public function execute(): void
            {
                $this->state->runs[] = self::getEventName();
            }
        };

        $eventer->register($shouldRunEvent::class);
        $eventer->register($shouldNotRunEvent::class);

        $eventer->emit($shouldRunEvent::getEventName(), [$state]);

        $this->assertEquals([$shouldRunEvent::getEventName()], $state->runs);
    }

    #[Test]
    public function before_runs_earlier_than_regular_listeners()
    {
        $eventer = new Eventer();

        $state = (object) ['runs' => []];

        $beforeEvent = new class ($state) implements EventInterface {
            public function __construct(private stdClass &$state)
            {
            }

            public static function getEventName(): string
            {
                return '::before event name::';
            }

            public function filter(): bool
            {
                return true;
            }

            public function execute(): void
            {
                $this->state->runs[] = 'before';
            }
        };

        $listenerEvent = new class ($state) implements EventInterface {
            public function __construct(private stdClass &$state)
            {
            }

            public static function getEventName(): string
            {
                return '::before event name::';
            }

            public function filter(): bool
            {
                return true;
            }

            public function execute(): void
            {
                $this->state->runs[] = 'listeners';
            }
        };

        $eventer->register($listenerEvent::class);
        $eventer->before($beforeEvent::class);

        $eventer->emit($beforeEvent::getEventName(), [$state]);

        $this->assertEquals(['before', 'listeners'], $state->runs);
    }

    #[Test]
    #[TestWith(['before', 'register'], 'Before, listeners')]
    #[TestWith(['beforeOnce', 'register'], 'BeforeOnce, listeners')]
    #[TestWith(['beforeOnce', 'registerOnce'], 'BeforeOnce, listenersOnce')]
    #[TestWith(['before', 'registerOnce'], 'Before, listenersOnce')]
    public function before_once_runs_earlier_than_regular_listeners(string $first, string $second)
    {
        $eventer = new Eventer();

        $state = (object) ['runs' => []];

        $beforeEvent = new class ($state) implements EventInterface {
            public function __construct(private stdClass &$state)
            {
            }

            public static function getEventName(): string
            {
                return '::before event name::';
            }

            public function filter(): bool
            {
                return true;
            }

            public function execute(): void
            {
                $this->state->runs[] = 'before';
            }
        };

        $listenerEvent = new class ($state) implements EventInterface {
            public function __construct(private stdClass &$state)
            {
            }

            public static function getEventName(): string
            {
                return '::before event name::';
            }

            public function filter(): bool
            {
                return true;
            }

            public function execute(): void
            {
                $this->state->runs[] = 'listeners';
            }
        };

        call_user_func_array([$eventer, $second], [$listenerEvent::class]);
        call_user_func_array([$eventer, $first], [$beforeEvent::class]);

        $eventer->emit($beforeEvent::getEventName(), [$state]);

        $this->assertEquals(['before', 'listeners'], $state->runs);
    }

    #[Test]
    #[TestWith(['registerOnce'], 'Register')]
    #[TestWith(['beforeOnce'], 'Before')]
    public function it_activates_once_listeners_one_time(string $registrar)
    {
        $eventer = new Eventer();

        $state = (object) ['runs' => 0];

        $event = new class ($state) implements EventInterface {
            public function __construct(private stdClass &$state)
            {
            }

            public static function getEventName(): string
            {
                return '::event name::';
            }

            public function filter(): bool
            {
                return true;
            }

            public function execute(): void
            {
                $this->state->runs++;
            }
        };

        call_user_func_array([$eventer, $registrar], [$event::class]);

        $eventer->emit('::event name::', [$state]);
        $eventer->emit('::event name::', [$state]);

        $this->assertEquals(1, $state->runs);
    }

    #[Test]
    #[TestWith(['register'], 'Register')]
    #[TestWith(['registerOnce'], 'Register once')]
    #[TestWith(['before'], 'Before')]
    #[TestWith(['beforeOnce'], 'Before once')]
    public function it_throws_an_exception_without_event_interface(string $registrar)
    {
        $eventer = new Eventer();

        $event = new class {
        };

        $this->expectException(InvalidEventException::class);
        call_user_func_array([$eventer, $registrar], [$event::class]);
    }
}
