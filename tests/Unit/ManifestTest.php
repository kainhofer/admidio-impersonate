<?php
/**
 * The manifest and the language files of the plugin.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\Impersonate\Tests\Unit;

use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Tests\Unit\Plugins\Support\PluginTestCase;

final class ManifestTest extends PluginTestCase
{
    private function readPlugin(): Plugin
    {
        return Plugin::read(dirname(__DIR__, 2));
    }

    /**
     * The keys of one language file of the plugin.
     * @return array<int,string>
     */
    private function languageKeys(string $language): array
    {
        $strings = simplexml_load_file(dirname(__DIR__, 2) . '/languages/' . $language . '.xml');
        $this->assertNotFalse($strings, 'languages/' . $language . '.xml is well-formed');

        $keys = array();
        foreach ($strings->string as $string) {
            $keys[] = (string)$string['name'];
        }
        sort($keys);

        return $keys;
    }

    public function testManifestIsValid(): void
    {
        $plugin = $this->readPlugin();

        $this->assertNull($plugin->error);
        $this->assertTrue($plugin->isValid());
        $this->assertSame('impersonate', $plugin->id);
        $this->assertFalse($plugin->wantsMenuEntry(), 'the plugin has pages, but none of them belongs in the menu');
    }

    public function testEverySettingLabelIsATranslatedKey(): void
    {
        $known = array_flip($this->languageKeys('en'));

        foreach ($this->readPlugin()->settings as $name => $setting) {
            foreach (array('label', 'description') as $field) {
                $key = $setting[$field] ?? '';
                $this->assertTrue(
                    Language::isTranslationStringId($key),
                    sprintf('The %s of the setting %s is a language key: %s', $field, $name, $key)
                );
                $this->assertArrayHasKey($key, $known, sprintf('The %s of the setting %s is in languages/en.xml', $field, $name));
            }
        }
    }

    public function testEveryLanguageHasTheSameTexts(): void
    {
        $english = $this->languageKeys('en');

        foreach (array('de', 'de-DE') as $language) {
            $this->assertSame($english, $this->languageKeys($language), 'languages/' . $language . '.xml has the texts of en.xml');
        }
    }

    public function testSettingNamesDoNotChange(): void
    {
        $this->assertSame(
            array('impersonate_roles', 'impersonate_targets_subset_only', 'impersonate_max_minutes', 'impersonate_notify_user'),
            array_keys($this->readPlugin()->settings)
        );
    }

    public function testTheDefaultsAreTheSafeOnes(): void
    {
        $settings = $this->readPlugin()->settings;

        $this->assertSame(array('All'), $settings['impersonate_roles']['default'], 'every administrator, and nobody else');
        $this->assertTrue($settings['impersonate_targets_subset_only']['default'], 'no user with rights the administrator lacks');
        $this->assertSame(30, $settings['impersonate_max_minutes']['default']);
        $this->assertFalse($settings['impersonate_notify_user']['default']);
    }
}
