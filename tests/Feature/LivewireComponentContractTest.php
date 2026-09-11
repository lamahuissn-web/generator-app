<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * A Livewire public method must never share a name with a public property.
 *
 * `wire:click="open"` compiles to a call on the `$wire` proxy, and the proxy resolves the
 * PROPERTY first: with both `public bool $open` and `public function open()`, the button
 * evaluates `$wire.open()` against a boolean. That is a TypeError inside a click handler —
 * no request, no dialog, no console entry worth noticing — so the button is simply dead.
 *
 * This is a STATIC contract, not behaviour, and that is exactly why it needs its own guard:
 * calling the component from a test (`->call('open')`) goes straight to the method and passes
 * while every real click does nothing. Only reflection sees the collision.
 */
class LivewireComponentContractTest extends TestCase
{
    /**
     * Components the app ships. Discovered from disk so a new one is covered automatically.
     *
     * @return array<int, class-string<Component>>
     */
    private function components(): array
    {
        $classes = [];

        // Recursive: components live at several depths (Admin/, Admin/Pages/, Collector/),
        // and a fixed-depth glob silently discovers only some of them.
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Livewire'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(
                ['/', '.php'],
                ['\\', ''],
                substr($file->getPathname(), strlen(app_path('Livewire')) + 1),
            );

            $classes[] = 'App\\Livewire\\'.$relative;
        }

        return array_values(array_filter($classes, function (string $class): bool {
            return class_exists($class) && is_subclass_of($class, Component::class);
        }));
    }

    public function test_no_public_method_shadows_a_public_property_on_a_livewire_component(): void
    {
        $components = $this->components();

        // A discovery bug would make this test vacuously pass.
        $this->assertGreaterThanOrEqual(8, count($components), 'Too few Livewire components found — has the layout changed?');

        $collisions = [];

        foreach ($components as $class) {
            $reflection = new ReflectionClass($class);

            // Declared on the component itself (or its traits) — inherited framework members
            // are not the component's own API and would only produce noise.
            $properties = array_map(
                fn (ReflectionProperty $p): string => $p->getName(),
                array_filter(
                    $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
                    fn (ReflectionProperty $p): bool => $p->getDeclaringClass()->getName() === $class,
                ),
            );

            $methods = array_map(
                fn (ReflectionMethod $m): string => $m->getName(),
                array_filter(
                    $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                    fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class && ! $m->isStatic(),
                ),
            );

            foreach (array_intersect($properties, $methods) as $name) {
                $collisions[] = $class.'::'.$name;
            }
        }

        $this->assertSame([], $collisions, sprintf(
            "A public method shares a name with a public property, so wire:click=\"%s\" calls a value "
            ."instead of the method and the button does nothing in a browser:\n  %s\n"
            .'Rename the method (the property keeps its name for the template) or the property.',
            Str::afterLast($collisions[0] ?? 'x::x', '::'),
            implode("\n  ", $collisions),
        ));
    }
}
