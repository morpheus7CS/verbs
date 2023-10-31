<?php

namespace Thunk\Verbs;

use Glhd\Bits\Snowflake;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionParameter;
use Thunk\Verbs\Support\EventSerializer;
use Thunk\Verbs\Support\EventStateRegistry;
use Thunk\Verbs\Support\PendingEvent;
use Thunk\Verbs\Support\Reflector;
use Thunk\Verbs\Support\StateCollection;
use WeakMap;

abstract class Event
{
    public int|string $id;

    public bool $fired = false;

    /** @return PendingEvent<static> */
    public static function make(...$args): PendingEvent
    {
        if ((count($args) === 1 && isset($args[0]) && is_array($args[0]))) {
            $args = $args[0];
        }

        // Turn a positional array to an associative array
        if (count($args) && ! Arr::isAssoc($args)) {
            if (! method_exists(static::class, '__construct')) {
                throw new InvalidArgumentException('You cannot pass positional arguments to '.class_basename(static::class).'::make()');
            }

            // TODO: Cache this
            $names = collect((new ReflectionMethod(static::class, '__construct'))->getParameters())
                ->map(fn (ReflectionParameter $parameter) => $parameter->getName());

            $args = $names->combine(collect($args)->take($names->count()))->all();
        }

        $event = app(EventSerializer::class)->deserialize(static::class, $args);

        $event->id = Snowflake::make()->id();

        return PendingEvent::make($event);
    }

    public static function fire(...$args)
    {
        return static::make(...$args)->fire();
    }

    public function states(): StateCollection
    {
        // TODO: This is a bit hacky, but is probably OK right now

        static $map = new WeakMap();

        return $map[$this] ??= app(EventStateRegistry::class)->getStates($this);
    }

    public function state(string $state_type = null): ?State
    {
        $states = collect($this->states());

        // If we only have one state, allow for accessing without providing a class
        if ($state_type === null && $states->count() === 1) {
            return $states->first();
        }

        return $states->firstWhere(fn (State $state) => $state::class === $state_type);
    }
}
