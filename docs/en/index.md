# Google Sitemaps Module

Silverstripe CMS provides support for the Google Sitemaps XML system, enabling
Google and other search engines to see all pages on your site. This helps
your Silverstripe CMS website rank well in search engines, and to encourage the
information on your site to be discovered by Google quickly.

Therefore, all Silverstripe CMS websites contain a special controller which can
be visited: http://yoursite.com/sitemap.xml. This is not a file directly, but
rather a custom route which points to the GoogleSitemap controller.

See http://en.wikipedia.org/wiki/Sitemaps for info on the Google Sitemap
format.

Whenever you publish a new or republish an existing page, Silverstripe CMS can
automatically inform Google of the change, encouraging a Google to take notice.
If you install the Silverstripe CMS Google Analytics module, you can see if Google
has updated your page as a result.

By default, Silverstripe CMS informs Google that the importance of a page depends
on its position of in the sitemap. "Top level" pages are most important, and
the deeper a page is nested, the less important it is. (For each level,
Importance drops from 1.0, to 0.9, to 0.8, and so on, until 0.1 is reached).

In the CMS, in the Settings tab for each page, you can set the importance
manually, including requesting to have the page excluded from the sitemap.

## Configuration

Most module configuration is done via the Silverstripe CMS Config API. Create a
new config file `mysite/_config/googlesitemaps.yml` with the following outline:

```yml
---
Name: customgooglesitemaps
After: googlesitemaps
---
Wilr\GoogleSitemaps\GoogleSitemap:
  enabled: true
  objects_per_sitemap: 1000
  use_show_in_search: true
```

You can now alter any of those properties to set your needs.

```yml
---
Name: customgooglesitemaps
After: googlesitemaps
---
Wilr\GoogleSitemaps\GoogleSitemap:
  enabled: true
  objects_per_sitemap: 1000
  use_show_in_search: true
```

### Including DataObjects

The module provides support for including DataObject subclasses as pages in the
SiteTree such as comments, forum posts and other pages which are stored in your
database as DataObject subclasses.

To include a DataObject instance in the Sitemap it requires that your subclass
defines two functions:

-   AbsoluteLink() function which returns the URL for this DataObject
-   canView() function which returns a boolean value.

The following is a barebones example of a DataObject called 'MyDataObject'. It
assumes that you have a controller called 'MyController' which has a show method
to show the DataObject by its ID.

    <?php

    use SilverStripe\ORM\DataObject;
    use SilverStripe\Control\Director;

    class MyDataObject extends DataObject {

    	function canView($member = null) {
    		return true;
    	}

    	function AbsoluteLink() {
    		return Director::absoluteURL($this->Link());
    	}

    	function Link() {
    		return 'MyController/show/'. $this->ID;
    	}
    }

After those methods have been defined on your DataObject you now need to tell
the Google Sitemaps module that it should be listed in the sitemap.xml file. To
do that, include the following in your \_config.php file.

    use Wilr\GoogleSitemaps\GoogleSitemap;

    GoogleSitemap::register_dataobject('MyDataObject');

If you need to change the frequency of the indexing, you can pass the change
frequency (daily, weekly, monthly) as a second parameter to register_dataobject(), So
instead of the previous code you would write:

    use Wilr\GoogleSitemaps\GoogleSitemap;

    GoogleSitemap::register_dataobject('MyDataObject', 'daily');

See the following blog post for more information:

http://www.silvercart.org/blog/dataobjects-and-googlesitemaps/

### Including custom routes

Occasionally you may have a need to include custom URLs in your sitemap for
your Controllers and other pages which don't exist in the database. To update
the sitemap to include those links call register_routes() with your array of
URLs to include.

    use Wilr\GoogleSitemaps\GoogleSitemap;

    GoogleSitemap::register_routes(array(
    	'/my-custom-controller/',
    	'/Security/',
    	'/Security/login/'
    ));

### Multi-language sites (Fluent integration)

When [tractorcow/silverstripe-fluent](https://github.com/tractorcow-farm/silverstripe-fluent)
is installed, the bundled `Wilr\GoogleSitemaps\Extensions\FluentSitemapExtension`
auto-attaches and expands the sitemap index so every localised (class, page)
entry is emitted once per configured locale, with a URL like:

    /sitemap.xml/sitemap/<ClassName>/<Page>/<Locale>

Each per-locale sub-sitemap is rendered with `FluentState::withState()`
wrapped around the underlying ORM query, so locale filtering happens in SQL
and the output does not depend on the visitor's persisted locale (cookie or
session).

Entries that are not localised — custom routes, and DataObjects without the
Fluent extension — are kept as a single entry rather than being repeated per
locale. Requests for a locale code that is not configured in Fluent return a
404.

The wiring is automatic via the `_config/fluent.yml` file shipped with this
module and gated on `Only: classexists` so installs without Fluent are
unaffected. If you'd rather opt out, remove the extension in your own YAML:

```yml
---
Name: app-googlesitemaps-fluent
After: googlesitemaps-fluent
---
Wilr\GoogleSitemaps\GoogleSitemap:
  extensions:
    FluentSitemapExtension: null
```

#### Extension hooks

Three hooks make per-locale behaviour easy to extend or replicate for other
localisation modules:

- `updateGoogleSitemaps($sitemaps)` — called from `GoogleSitemap::getSitemaps()`
  after the standard list is built. Mutate the passed `ArrayList` in place to
  add/remove/expand entries (each entry can carry a `Locale` field that the
  index template renders into the URL).
- `withLocale(string $locale, callable $callback, &$result, &$handled)` —
  called from `GoogleSitemap::inLocale()` whenever `getItems()` is invoked
  with a locale code. Set `$handled = true` and assign to `$result` to
  short-circuit the default fetch with your localisation module's state.
- `updateSupportsLocale(string $locale, bool &$supported)` — called from
  `GoogleSitemap::supportsLocale()` before a locale-suffixed sub-sitemap is
  served. Set `$supported = true` for locale codes your module can render;
  anything else returns a 404.

### Sitemapable

For automatic registration of a DataObject subclass, implement the `Sitemapable`
extension.

```
<?php


class MyDataObject extends DataObject implements Sitemapable
{
    public function AbsoluteLink()
    {
        // ..
    }
}
```
