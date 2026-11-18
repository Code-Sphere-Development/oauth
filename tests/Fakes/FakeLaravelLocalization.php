<?php

namespace CodeSphere\OAuth\Tests\Fakes;

/**
 * Stands in for mcamara/laravel-localization's 'laravellocalization'
 * binding: only the methods the package calls.
 */
class FakeLaravelLocalization
{
    public ?string $currentLocale = null;

    /**
     * @param  list<string>  $supported
     */
    public function __construct(private readonly array $supported = ['de', 'en']) {}

    /**
     * @return list<string>
     */
    public function getSupportedLanguagesKeys(): array
    {
        return $this->supported;
    }

    public function getLocalizedURL(?string $locale = null, ?string $url = null): string|false
    {
        if ($locale === null || ! in_array($locale, $this->supported, true)) {
            return false;
        }

        $parts = parse_url((string) $url);
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? '')));

        if (isset($segments[0]) && in_array($segments[0], $this->supported, true)) {
            $segments[0] = $locale;
        } else {
            array_unshift($segments, $locale);
        }

        return ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? 'localhost')
            .'/'.implode('/', $segments)
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    public function setLocale(?string $locale = null): ?string
    {
        return $this->currentLocale = $locale;
    }
}
