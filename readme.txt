=== LOW Dealer Locator ===
Contributors: TODO_FILL_IN
Tags: dealer locator, store locator, zip code, map, leaflet
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.3.1
License: TODO_FILL_IN
License URI: TODO_FILL_IN

Attach service-area zip codes to dealer records and show them on a map visitors can search.

== Description ==

LOW Dealer Locator attaches service-area zip codes to dealer records, publishes
those dealers as JSON at `/wp-json/low-dealer-locator/v1/dealers`, and shows a
map locator. A visitor can search by zip code, address, or their current
location. A zip that a dealer lists is shown first. If none do, the locator
shows the nearest dealers by distance.

* Choose which post types are dealers, or register a Dealer Locator post type.
* Enter service-area zip codes on each dealer, or import them from a CSV file.
* Read contact, address, and coordinate values from the plugin's own fields,
  custom post meta, ACF fields, or core post fields.
* Look up coordinates from a dealer's address when the dealer is saved.
* Serve a public JSON list of published dealers.
* Search by zip, street address, or the visitor's location.
* Show a zip match first, then the nearest dealers, with distance in miles or
  kilometers.
* Place the locator with a shortcode, a block, or a widget.
* Draw the map with the bundled Leaflet library and a configurable tile URL.
  The default tiles are OpenStreetMap.
* Check for updates from GitHub releases on the Plugins screen.

== Installation ==

1. Activate the plugin.
2. Open Settings > Dealer Locator. Select the post types that hold dealers, or
   check "Create a Dealer Locator post type".
3. Edit a dealer and enter zip codes in the Service Area Zip Codes box, or
   import a CSV on the Import tab.
4. Add the locator with the shortcode `[low_dealer_locator]`, the Dealer
   Locator block, or the Dealer Locator widget.

== Frequently Asked Questions ==

= How do I import zip codes with a CSV? =

Open Settings > Dealer Locator and use the Import tab. The file needs a header
row with the columns `dealer` and `zip`. The dealer column is a post ID or an
exact title. Put one zip on each row, or several zips in one cell separated by
commas, spaces, or semicolons.

Add to existing zips merges the file into the zips already stored for those
dealers. Replace existing zips for dealers in this file overwrites the stored
zips of dealers who appear in the file, and it requires the confirmation
checkbox. Dealers who are not in the file are left unchanged. Values that are
not valid zip codes are skipped.

= How do I map my existing custom or ACF fields? =

Open the Field Mapping tab. For each dealer post type, point email, website,
phone, street, city, state, zip, latitude, and longitude at a plugin field, a
custom field (post meta), an ACF field, or a core post field. The dealer name
is always the post title.

Mapping is read-only. The plugin reads the mapped meta, ACF, and core values
when it builds the locator. The Dealer Details box and the geocoder write only
the plugin's own fields.

= Why is a dealer missing from distance results? =

Distance results include only dealers that have numeric latitude and longitude.
Dealers without coordinates are skipped. On dealer list screens, an admin
notice names the dealers that have no map coordinates and are left out of
distance results.

Coordinates are looked up from the address when the dealer is saved. Check
"Don't auto-geocode this dealer (use the coordinates entered here)" to keep
the latitude and longitude you entered. A dealer can still appear in a zip
match without coordinates, because that match uses the zip list rather than
distance.

= Does the address lookup need a paid service? =

No. The default geocoder is the public Nominatim service at
nominatim.openstreetmap.org. It is meant for light use. Dealer addresses are
sent when a dealer is saved, and visitor addresses or zip codes that are not
in the local centroid table are sent from the server during a search. You can
replace the geocoder URL on the Geocoding tab.

= What is the JSON format? =

`GET /wp-json/low-dealer-locator/v1/dealers` returns published dealers:

    {
      "dealers": [
        {
          "id": 42,
          "name": "Example Dealer",
          "email": "dealer@example.com",
          "website": "https://example.com",
          "phone": "555-0100",
          "address": {
            "street": "1 Main Street",
            "city": "Austin",
            "state": "TX",
            "zip": "78701"
          },
          "lat": 30.2672,
          "lng": -97.7431,
          "zip_codes": ["78701", "78702"]
        }
      ]
    }

`lat` and `lng` are null when the dealer has no coordinates. `zip_codes` is
the dealer's service-area list.

= What shortcode options are there? =

`[low_dealer_locator height="600" zoom="10"]`

`height` is the map height in pixels, from 200 to 1200. `zoom` is the default
zoom, from 1 to 18. Leave either one out, or pass a value outside that range,
and the locator uses the map height and default zoom from the Locator tab.
The Dealer Locator block and widget accept the same two values.

= How do plugin updates work? =

On the Plugins screen, choose Check for updates. WordPress installs a GitHub
release only when its version is newer than the installed plugin. Publish the
release with a tag such as v0.3.1. Do not upload that GitHub zip through
Plugins, Add New. WordPress would place it in a second folder named after the
tag instead of replacing this plugin.

= How do I stop the plugin from deleting data? =

On the General tab, "Delete all plugin data when the plugin is deleted" is
off by default. Leave it off. Deleting the plugin then leaves the tables,
settings, dealer fields, and transients in place. Turning it on removes that
plugin data on uninstall. Dealer posts themselves are never deleted.

= Why don't scheduled geocoding or the zip data load run? =

Geocoding runs on the WP-Cron hook `low_dl_process_geo_queue`. If the bundled
zip centroid table still needs to be loaded after activation, that work is
scheduled on `low_dl_load_centroids`. Neither scheduled event runs while
WP-Cron is disabled, unless a server cron job requests `wp-cron.php`.

Reactivating the plugin loads the zip centroid table during activation, so
that load does not wait on WP-Cron. Scheduled geocoding still waits for its
cron event.

= What if my site is behind a proxy or CDN and address searches are rate limited together? =

Address searches that need the geocoder are limited per IP. The plugin reads
that IP from `REMOTE_ADDR`. When every visitor shares the proxy's address,
they share one limit. The `low_dl_client_ip` filter receives that address, or
`unknown` when it is not a valid IP, and may return the visitor IP the limit
should use.

== Developer Hooks ==

Filters:

* `low_dl_dealer_cpt_args` - Filters the arguments used to register the
  dealer_locator post type.
* `low_dl_dealers_data` - Filters the published dealer list before it is
  cached for the JSON endpoint.
* `low_dl_cache_ttl` - Filters how many seconds that list stays cached. The
  default is 12 hours.
* `low_dl_response_max_age` - Filters the Cache-Control max-age on the dealers
  response. The default is 300 seconds.
* `low_dl_geocoder_user_agent` - Filters the User-Agent sent with geocoder
  requests.
* `low_dl_search_rate_limit` - Filters the per-IP limit for remote geocoding
  during search. The default array is `max` 10 and `window` 60 seconds.
* `low_dl_client_ip` - Filters the IP address used for that rate limit. The
  default is taken from `REMOTE_ADDR`.

Actions:

* `low_dl_zips_saved` - Fires after a dealer's zip codes are saved. Receives
  the post ID and the stored zip codes.
* `low_dl_details_saved` - Fires after the plugin saves its own dealer detail
  fields. Receives the post ID.

== Third-Party Services and Privacy ==

Map tiles are requested by each visitor's browser from the configured tile
server. The default is tile.openstreetmap.org. The visitor's IP address and
the requested map area are sent to that server. See the
OpenStreetMap tile usage policy
(https://operations.osmfoundation.org/policies/tiles/) and the
OpenStreetMap Foundation privacy policy
(https://osmfoundation.org/wiki/Privacy_Policy).

Address geocoding sends dealer addresses, in the background when a dealer is
saved, to the configured geocoder. Visitor-typed addresses, and zip codes that
are not in the local centroid table, are sent from the server during a search.
The default geocoder is Nominatim at nominatim.openstreetmap.org. Each request
uses a User-Agent that contains the site name, the site URL, and the contact
email from the Geocoding tab, or the site admin email when that field is
empty. See the Nominatim usage policy
(https://operations.osmfoundation.org/policies/nominatim/).

Coordinates from "Use my location" are sent only to this site's own search
endpoint. The plugin does not store them. The plugin sets no cookies.

If you use another geocoder or tile provider, review that provider's terms and
privacy policy. The public OpenStreetMap tile and Nominatim services are for
light use.

== Credits ==

* Leaflet, BSD-2-Clause license: https://leafletjs.com
* OpenStreetMap contributors. Map data is © OpenStreetMap contributors and is
  available under the Open Database License:
  https://www.openstreetmap.org/copyright
* Nominatim, for the default address geocoder.
* US Census Bureau ZIP Code Tabulation Area Gazetteer, public domain, for the
  bundled zip centroid table.

ZCTAs approximate zip codes and do not cover every USPS zip code. A zip that
is not in the table is looked up through Nominatim.

== Changelog ==

= 0.3.1 =
* Readme explains how Check for updates installs a newer GitHub release.

= 0.3.0 =
* Initial release.
* Updates install from GitHub releases. The Plugins screen includes Check for
  updates.
