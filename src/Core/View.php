<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\I18n\Formatter;
use SecondStay\I18n\Locales;
use SecondStay\I18n\Translator;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class View
{
    private Environment $twig;

    /** @var array<string, mixed> */
    private array $globals = [];

    public function __construct(
        string $templatesPath,
        private readonly Translator $translator,
        private Formatter $formatter,
        private readonly Router $router,
        ?string $cachePath = null,
        bool $debug = false,
    ) {
        $loader = new FilesystemLoader($templatesPath);
        $this->twig = new Environment($loader, [
            'cache' => $cachePath !== null && $cachePath !== '' ? $cachePath : false,
            'debug' => $debug,
            // Les templates changent lors d'une mise à jour applicative :
            // le cache doit toujours se réinvalider sur mtime.
            'auto_reload' => true,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        if ($debug) {
            $this->twig->addExtension(new DebugExtension());
        }

        $this->registerFunctions();
    }

    public function setFormatter(Formatter $formatter): void
    {
        $this->formatter = $formatter;
    }

    public function share(string $key, mixed $value): void
    {
        $this->globals[$key] = $value;
        $this->twig->addGlobal($key, $value);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }

    public function exists(string $template): bool
    {
        return $this->twig->getLoader()->exists($template);
    }

    public function twig(): Environment
    {
        return $this->twig;
    }

    private function registerFunctions(): void
    {
        $translator = $this->translator;
        $router = $this->router;

        $this->twig->addFunction(new TwigFunction(
            't',
            /**
             * @param array<string, string|int|float> $parameters
             */
            static fn (string $key, array $parameters = [], ?string $locale = null): string
                => $translator->trans($key, $parameters, $locale)
        ));

        $this->twig->addFunction(new TwigFunction(
            'tc',
            /**
             * @param array<string, string|int|float> $parameters
             */
            static fn (string $key, int $count, array $parameters = []): string
                => $translator->transChoice($key, $count, $parameters)
        ));

        $this->twig->addFunction(new TwigFunction(
            'path',
            /**
             * @param array<string, string|int> $params
             */
            function (string $name, array $params = [], ?string $locale = null) use ($router, $translator): string {
                $base = is_string($this->globals['base_path'] ?? null) ? $this->globals['base_path'] : '';

                return $base . $router->path($name, $params, $locale ?? $translator->locale());
            }
        ));

        $this->twig->addFunction(new TwigFunction('locales', static fn (): array => Locales::ALL));
        $this->twig->addFunction(new TwigFunction(
            'locale_name',
            static fn (string $locale): string => Locales::nativeName($locale)
        ));

        $this->twig->addFilter(new TwigFilter('money', fn (int $cents): string => $this->formatter->money($cents)));
        $this->twig->addFilter(new TwigFilter(
            'localdate',
            fn (\DateTimeInterface $date, string $width = 'medium'): string => $this->formatter->date($date, $width)
        ));
        $this->twig->addFilter(new TwigFilter(
            'monthname',
            fn (\DateTimeInterface $date): string => $this->formatter->monthName($date)
        ));
        $this->twig->addFilter(new TwigFilter(
            'daymonth',
            fn (\DateTimeInterface $date): string => $this->formatter->dayAndMonth($date)
        ));
        $this->twig->addFunction(new TwigFunction(
            'weekday_names',
            /** @return list<string> */
            fn (): array => $this->formatter->weekdayNames()
        ));
        $this->twig->addFilter(new TwigFilter(
            'localdatetime',
            fn (\DateTimeInterface $date, string $width = 'medium'): string => $this->formatter->dateTime($date, $width)
        ));
    }
}
