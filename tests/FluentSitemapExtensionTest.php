<?php

namespace Wilr\GoogleSitemaps\Tests;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentDirectorExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use Wilr\GoogleSitemaps\Extensions\FluentSitemapExtension;
use Wilr\GoogleSitemaps\Extensions\GoogleSitemapExtension;
use Wilr\GoogleSitemaps\GoogleSitemap;
use Wilr\GoogleSitemaps\Tests\Model\TestDataObject;

/**
 * Tests for the Fluent integration extension. The whole class skips when
 * Fluent isn't installed so the test suite stays green for installs that
 * don't pull in the optional package.
 */
class FluentSitemapExtensionTest extends FunctionalTest
{
    protected static $fixture_file = 'FluentSitemapExtensionTest.yml';

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestDataObject::class,
    ];

    protected static $extra_extensions = [
        TestDataObject::class => [
            GoogleSitemapExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        if (!class_exists(Locale::class) || !class_exists(FluentState::class)) {
            $this->markTestSkipped('Fluent is not installed; skipping FluentSitemapExtensionTest');
        }

        parent::setUp();

        GoogleSitemap::clear_registered_dataobjects();
        GoogleSitemap::clear_registered_routes();

        Config::modify()->set(GoogleSitemap::class, 'enabled', true);
        Config::modify()->set(FluentDirectorExtension::class, 'disable_default_prefix', false);

        // Wire up the extension explicitly for the test rather than relying
        // on the Only/classexists yaml condition, which behaves differently
        // depending on bootstrap order.
        if (!GoogleSitemap::has_extension(FluentSitemapExtension::class)) {
            GoogleSitemap::add_extension(FluentSitemapExtension::class);
        }
    }

    protected function tearDown(): void
    {
        GoogleSitemap::clear_registered_dataobjects();
        GoogleSitemap::clear_registered_routes();

        parent::tearDown();
    }

    /**
     * Creates and publishes a parent/child page pair in both fixture locales,
     * with a different URL segment per locale.
     */
    protected function createLocalisedPages(): void
    {
        $segments = [
            'en_NZ' => ['parent-en', 'child-en'],
            'fr_FR' => ['parent-fr', 'child-fr'],
        ];

        $parentID = 0;
        $childID = 0;

        foreach ($segments as $locale => [$parentSegment, $childSegment]) {
            FluentState::singleton()->withState(
                function (FluentState $state) use ($locale, $parentSegment, $childSegment, &$parentID, &$childID) {
                    $state->setLocale($locale);

                    $parent = $parentID ? SiteTree::get()->byID($parentID) : SiteTree::create();
                    $parent->Title = $parentSegment;
                    $parent->URLSegment = $parentSegment;
                    $parent->write();
                    $parent->publishSingle();
                    $parentID = $parent->ID;

                    $child = $childID ? SiteTree::get()->byID($childID) : SiteTree::create();
                    $child->Title = $childSegment;
                    $child->URLSegment = $childSegment;
                    $child->ParentID = $parentID;
                    $child->write();
                    $child->publishSingle();
                    $childID = $child->ID;
                }
            );
        }
    }

    public function testGetSitemapsExpandsLocalisedClassesOnly(): void
    {
        $this->createLocalisedPages();
        GoogleSitemap::register_dataobject(TestDataObject::class);
        GoogleSitemap::register_route('/custom-route/');

        $sitemaps = GoogleSitemap::inst()->getSitemaps();

        $pages = $sitemaps->filter('ClassName', 'SilverStripe-CMS-Model-SiteTree');
        $locales = $pages->column('Locale');
        sort($locales);
        $this->assertSame(['en_NZ', 'fr_FR'], $locales, 'One SiteTree entry per locale');

        $objects = $sitemaps->filter('ClassName', 'Wilr-GoogleSitemaps-Tests-Model-TestDataObject');
        $this->assertSame(1, $objects->count(), 'Non-localised dataobjects are not duplicated per locale');
        $this->assertNull($objects->first()->Locale);

        $routes = $sitemaps->filter('ClassName', 'GoogleSitemapRoute');
        $this->assertSame(1, $routes->count(), 'Custom routes are not duplicated per locale');
    }

    public function testGetSitemapsLeavesIndexUntouchedWhenNoLocalesConfigured(): void
    {
        $this->createLocalisedPages();

        // Wipe out fixture locales so the extension has nothing to expand to.
        foreach (Locale::get() as $locale) {
            $locale->delete();
        }
        Locale::clearCached();

        $sitemaps = GoogleSitemap::inst()->getSitemaps();
        $pages = $sitemaps->filter('ClassName', 'SilverStripe-CMS-Model-SiteTree');

        $this->assertSame(1, $pages->count(), 'Without configured locales the standard single entry is preserved');
        $this->assertNull($pages->first()->Locale);
    }

    public function testWithLocaleHookSwitchesFluentState(): void
    {
        $extension = new FluentSitemapExtension();

        $captured = null;
        $result = null;
        $handled = false;

        $extension->withLocale(
            'fr_FR',
            function () use (&$captured) {
                $captured = FluentState::singleton()->getLocale();
                return 'callback-return-value';
            },
            $result,
            $handled
        );

        $this->assertTrue($handled, 'Extension should mark the call as handled');
        $this->assertSame('fr_FR', $captured, 'Callback should run inside the requested locale state');
        $this->assertSame('callback-return-value', $result);
    }

    public function testWithLocaleIgnoresUnknownLocaleCodes(): void
    {
        $extension = new FluentSitemapExtension();

        $invoked = false;
        $result = null;
        $handled = false;

        $extension->withLocale(
            'zz_ZZ',
            function () use (&$invoked) {
                $invoked = true;
                return 'should-not-run';
            },
            $result,
            $handled
        );

        $this->assertFalse($handled, 'Unknown locales must not short-circuit the default fetch');
        $this->assertFalse($invoked);
        $this->assertNull($result);
    }

    public function testIndexPageRendersPerLocaleEntries(): void
    {
        $this->createLocalisedPages();

        $body = $this->get('sitemap.xml')->getBody();

        $this->assertStringContainsString('sitemap/SilverStripe-CMS-Model-SiteTree/1/en_NZ', $body);
        $this->assertStringContainsString('sitemap/SilverStripe-CMS-Model-SiteTree/1/fr_FR', $body);
    }

    public function testLocalisedSubSitemapIgnoresPersistedLocale(): void
    {
        $this->createLocalisedPages();

        // Simulate a visitor whose persisted locale differs from the one
        // requested in the URL.
        $body = $this->get(
            'sitemap.xml/sitemap/SilverStripe-CMS-Model-SiteTree/1/fr_FR',
            null,
            null,
            ['FluentLocale' => 'en_NZ']
        )->getBody();

        preg_match_all('#<loc>([^<]+)</loc>#', $body, $matches);
        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $loc) {
            $this->assertStringContainsString('/fr/', $loc, 'Every <loc> is in the requested locale');
        }

        $this->assertStringContainsString('/fr/parent-fr/child-fr', $body, 'Nested pages use localised parent segments');
        $this->assertStringNotContainsString('parent-en/child-fr', $body);
    }

    public function testUnknownLocaleReturnsNotFound(): void
    {
        $this->createLocalisedPages();

        $response = $this->get('sitemap.xml/sitemap/SilverStripe-CMS-Model-SiteTree/1/zz_ZZ');

        $this->assertSame(404, $response->getStatusCode());
    }
}
