<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

/**
 * Catalogues de traduction (#15) : chaque chaîne traduisible du code (UcpBridgeI18n::admin/shop/api en PHP,
 * {l s='...' d='Modules.Ucpbridge.*'} en Smarty) a sa traduction française et anglaise, sans chaîne orpheline ni
 * paramètre perdu ; les textes sources sont en anglais ; les modèles d'e-mail anglais sont en anglais.
 */
final class TranslationsTest extends TestCase
{
    const DOMAINS = ['admin' => 'ModulesUcpbridgeAdmin', 'shop' => 'ModulesUcpbridgeShop', 'api' => 'ModulesUcpbridgeApi'];
    const LOCALES = ['fr-FR', 'en-US'];

    private static function root()
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, string[]> domaine XLIFF => textes sources trouvés dans le code */
    public static function sources(): array
    {
        $found = array_fill_keys(array_values(self::DOMAINS), []);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root(), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = $f->getPathname();
            if (false !== strpos($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $ext = $f->getExtension();
            if ('php' === $ext) {
                preg_match_all("/UcpBridgeI18n::(admin|shop|api)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", file_get_contents($path), $m, PREG_SET_ORDER);
                foreach ($m as $x) {
                    $found[self::DOMAINS[$x[1]]][] = strtr($x[2], ['\\\\' => '\\', "\\'" => "'"]);
                }
            } elseif ('tpl' === $ext) {
                preg_match_all("/\\{l s='((?:[^'\\\\]|\\\\.)*)'[^}]*?d='Modules\\.Ucpbridge\\.(Admin|Shop|Api)'/", file_get_contents($path), $m, PREG_SET_ORDER);
                foreach ($m as $x) {
                    $found['ModulesUcpbridge' . $x[2]][] = str_replace("\\'", "'", $x[1]);
                }
            }
        }

        return array_map(function ($l) {
            return array_values(array_unique($l));
        }, $found);
    }

    /** @return array<string, string> source => cible */
    private static function catalogue($domain, $locale): array
    {
        $file = self::root() . '/translations/' . $locale . '/' . $domain . '.' . $locale . '.xlf';
        self::assertFileExists($file);
        $xml = simplexml_load_file($file);
        self::assertNotFalse($xml, $file);
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
        $out = [];
        foreach ($xml->xpath('//x:trans-unit') as $u) {
            $u->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
            $out[(string) $u->xpath('x:source')[0]] = (string) $u->xpath('x:target')[0];
        }

        return $out;
    }

    public function testDesChainesTraduisiblesDansChaqueDomaine(): void
    {
        foreach (self::sources() as $domain => $strings) {
            $this->assertNotEmpty($strings, $domain);
        }
    }

    public function testChaqueChaineATraductionFrancaiseEtAnglaise(): void
    {
        foreach (self::sources() as $domain => $strings) {
            foreach (self::LOCALES as $locale) {
                $cat = self::catalogue($domain, $locale);
                foreach ($strings as $s) {
                    $this->assertArrayHasKey($s, $cat, "$domain $locale : traduction absente pour « $s »");
                    $this->assertNotSame('', trim($cat[$s]), "$domain $locale : traduction vide pour « $s »");
                    preg_match_all('/%[a-z_]+%/', $s, $p);
                    foreach ($p[0] as $param) {
                        $this->assertStringContainsString($param, $cat[$s], "$domain $locale : paramètre $param perdu pour « $s »");
                    }
                }
                $orphans = array_diff(array_keys($cat), $strings);
                $this->assertSame([], array_values($orphans), "$domain $locale : chaînes orphelines");
            }
        }
    }

    public function testTextesSourcesEnAnglais(): void
    {
        foreach (self::sources() as $domain => $strings) {
            foreach ($strings as $s) {
                $this->assertDoesNotMatchRegularExpression('/[éèêàçùôîû]/u', $s, "$domain : texte source en français : « $s »");
            }
        }
    }

    public function testTraductionsFrancaisesDifferentesDeLAnglais(): void
    {
        $same = 0;
        $total = 0;
        foreach (self::sources() as $domain => $strings) {
            $cat = self::catalogue($domain, 'fr-FR');
            foreach ($strings as $s) {
                ++$total;
                $same += $cat[$s] === $s ? 1 : 0;
            }
        }
        // Quelques chaînes identiques dans les deux langues (codes, noms techniques), jamais la majorité.
        $this->assertLessThan($total / 10, $same);
    }

    public function testModelesDEMailAnglaisEnAnglais(): void
    {
        foreach (glob(self::root() . '/mails/en/*.{html,txt}', GLOB_BRACE) as $f) {
            $text = file_get_contents($f);
            $this->assertDoesNotMatchRegularExpression('/[éèêàçù]|Bonjour|commande|rétractation/u', $text, basename($f));
            $this->assertStringNotContainsString('lang="fr"', $text, basename($f));
        }
    }
}
