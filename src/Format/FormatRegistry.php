<?php

namespace Ernestdefoe\Folio\Format;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;

class FormatRegistry
{
    /** @var array<string, Format>|null */
    private ?array $built = null;

    /** @param array<int, class-string<Format>> $classes */
    public function __construct(
        private Container $container,
        private SettingsRepositoryInterface $settings,
        private array $classes,
    ) {
    }

    /** @param class-string<Format> $class */
    public function add(string $class): void
    {
        $this->classes[] = $class;
        $this->built = null;
    }

    /** @return array<string, Format> keyed by Format::key(), in registration order */
    public function all(): array
    {
        if ($this->built === null) {
            $this->built = [];

            foreach ($this->classes as $class) {
                $format = $this->container->make($class);
                $this->built[$format->key()] = $format;
            }
        }

        return $this->built;
    }

    /**
     * 🚨 A format nobody configured is ON. Settings defaults only exist for the
     * three built-ins, so reading an unset key as "off" would make every
     * format another extension adds invisible until an admin found a switch.
     */
    public function enabled(string $key): bool
    {
        if (! isset($this->all()[$key])) {
            return false;
        }

        $value = $this->settings->get('ernestdefoe-folio.format_'.$key);

        return $value === null || (bool) $value;
    }

    public function get(string $key): ?Format
    {
        return $this->enabled($key) ? $this->all()[$key] : null;
    }

    /** @return array<int, array{key:string,label:string,icon:string,avatars:bool}> */
    public function describeEnabled(): array
    {
        $out = [];

        foreach ($this->all() as $key => $format) {
            if ($this->enabled($key)) {
                $out[] = [
                    'key' => $key,
                    'label' => $format->label(),
                    'icon' => $format->icon(),
                    'avatars' => $format->supportsAvatars(),
                ];
            }
        }

        return $out;
    }
}
