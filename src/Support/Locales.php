<?php

namespace CodeSphere\OAuth\Support;

use Illuminate\Http\Request;

/**
 * The locales the app has translations for.
 *
 * The translations live in the app; the package only decides which of them
 * is used. Locales are two-letter codes (de, en, …), matching the profile
 * language in CodeSphere Accounts and the primary Accept-Language subtag.
 */
class Locales
{
    /** Used when nothing else matches, even if the app has no lang/en. */
    public const FALLBACK = 'en';

    /** Directory and file names that count as a locale. */
    private const PATTERN = '/^[a-z]{2}$/';

    /**
     * Per-process cache of the detected locales, keyed by lang path.
     *
     * @var array<string, list<string>>
     */
    private static array $detected = [];

    /**
     * config('codesphere.locales') when set, otherwise the locales found in
     * the app's lang directory.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $configured = config('codesphere.locales');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter($configured, 'is_string'));
        }

        return $this->detect(lang_path());
    }

    public function isAvailable(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, $this->available(), true);
    }

    /**
     * The first Accept-Language entry whose primary subtag is available.
     */
    public function fromAcceptLanguage(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $language = strtolower(substr($language, 0, 2));

            if ($this->isAvailable($language)) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Forget the detected locales, e.g. after adding a lang directory.
     */
    public static function flush(): void
    {
        self::$detected = [];
    }

    /**
     * lang/{locale}.json files and lang/{locale}/ directories, plus 'en'.
     *
     * @return list<string>
     */
    private function detect(string $path): array
    {
        if (isset(self::$detected[$path])) {
            return self::$detected[$path];
        }

        $names = [self::FALLBACK];

        if (is_dir($path)) {
            foreach (glob($path.'/*.json') ?: [] as $file) {
                $names[] = basename($file, '.json');
            }

            foreach (glob($path.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
                $names[] = basename($directory);
            }
        }

        $locales = array_unique(array_filter(
            $names,
            fn (string $name) => $name !== 'vendor' && preg_match(self::PATTERN, $name) === 1
        ));
        sort($locales);

        return self::$detected[$path] = $locales;
    }
}
