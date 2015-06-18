=== TYPO3 tt_news Importer ===

Contributors: comprock,saurabhd,subharanjan
Donate link: https://axelerant.com/about-axelerant/donate/
Tags: typo3, import
Requires at least: 3.9.2
Tested up to: 4.3.0
Stable tag: 2.3.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

TYPO3 tt_news Importer easily imports thousands of tt_news and tx_comments from TYPO3 into WordPress.

== Description ==

TYPO3 tt_news Importer brings your TYPO3 news, related media and comments into WordPress with minimal fuss. You can be as selective or open as you'd like for selecting which tt_news records to grab. Import can be interrupted and restarted later on.

Inline and related images will be added to the Media Library. The first image found is optionally set as the Featured Image for the post. Inline images will have their source URLs updated. If there's more than one related image, the [ gallery] shortcode is optionally inserted into the post.

* Requires remote web and database access to the source TYPO3 instance.
* Comments will be tested for spam via Askimet if you have Askimet configured.
* Files and links will be appended to post content with optional shortcode wrappers, like [member]|[/member].  
* Post status override is possible, but hidden posts, will be set as Drafts.
* Opps and Restore options provide quick clean up and hiding of imports.

= Filter Options =

* `t3i_prepare_content` - Modify `tt_news.bodytext` before import
	* Example: See `fpjq_t3i_prepare_content` at bottom of `typo3-import.php`

= TYPO3 tt_news Importer Options =

**TYPO3 Access**

* Website URL
* Skip URL check
* Database Host
* Database Name
* Database Username
* Database Password

**News Selection**

* News WHERE Clause
* News ORDER Clause
* News to Import
* Categories to Import
* Skip Importing News

**Import Options**

* Default Author
* Protected Post Password
* Override Post Status as...?
	* No Change
	* Draft
	* Publish
	* Pending
	* Future
	* Private
* Insert More Link?
	* No
	* After 1st paragraph
	* After 2nd paragraph
	* After 3rd paragraph
	* After 4th paragraph
	* After 5th paragraph
	* After 6th paragraph
	* After 7th paragraph
	* After 8th paragraph
	* After 9th paragraph
	* After 10th paragraph
* Set Featured Image?
* Insert Gallery Shortcode?
	* No
	* After 1st paragraph
	* After 2nd paragraph
	* After 3rd paragraph
	* After 4th paragraph
	* After 5th paragraph
	* After 6th paragraph
	* After 7th paragraph
	* After 8th paragraph
	* After 9th paragraph
	* After 10th paragraph
	* After content'
* Related Files Header
* Related Files Header Tag
	* None
	* H1
	* H2
	* H3
	* H4
	* H5
	* H6
* Related Files Wrap
* Related Links Header
* Related Links Header Tag
	* None
	* H1
	* H2
	* H3
	* H4
	* H5
	* H6
* Related Links Wrap
* Approve Non-spam Comments?
* Decode Entities?
* Log Imported Files?

**Testing Options**

* Don't Import Comments
* Don't Import Media
* Import Limit
* Debug Mode

**Oops**

* Convert Imported Posts to Private, NOW!

**Reset/Restore**

* Delete...
	* Prior imports
	* Imported comments
	* Unattached media
* Reset plugin


== Installation ==

= Requirements =

* TBD

= Install Methods =

* Through WordPress Admin > Plugins > Add New, Search for "TYPO3 tt_news Importer"
	* Find "TYPO3 tt_news Importer"
	* Click "Install Now" of "TYPO3 tt_news Importer"
* Download [`typo3-importer.zip`](http://downloads.wordpress.org/plugin/typo3-importer.zip) locally
	* Through WordPress Admin > Plugins > Add New
	* Click Upload
	* "Choose File" `typo3-importer.zip`
	* Click "Install Now"
* Download and unzip [`typo3-importer.zip`](http://downloads.wordpress.org/plugin/typo3-importer.zip) locally
	* Using FTP, upload directory `typo3-importer` to your website's `/wp-content/plugins/` directory

= Activation Options =

* Activate the "TYPO3 tt_news Importer" plugin after uploading
* Activate the "TYPO3 tt_news Importer" plugin through WordPress Admin > Plugins

= Usage =

1. Set TYPO3 access through WordPress Admin > Settings > TYPO3 tt_news Importer
1. Import via WordPress Admin > Tools > TYPO3 tt_news Importer

= Upgrading =

* Through WordPress
	* Via WordPress Admin > Dashboard > Updates, click "Check Again"
	* Select plugins for update, click "Update Plugins"
* Using FTP
	* Download and unzip [`typo3-importer.zip`](http://downloads.wordpress.org/plugin/typo3-importer.zip) locally
	* Upload directory `typo3-importer` to your website's `/wp-content/plugins/` directory
	* Be sure to overwrite your existing `typo3-importer` folder contents


== Frequently Asked Questions ==

= Most Common Issues =

* Got `Parse error: syntax error, unexpected T_STATIC, expecting ')'`? Read [Most Axelerant Plugins Require PHP 5.3+](https://nodedesk.zendesk.com/hc/en-us/articles/202331041) for the fixes.
* [Debug common theme and plugin conflicts](https://nodedesk.zendesk.com/hc/en-us/articles/202330781)

= Still Stuck or Want Something Done? Get Support! =

1. [TYPO3 tt_news Importer Knowledge Base](https://nodedesk.zendesk.com/hc/en-us/sections/200861112-WordPress-FAQs) - read and comment upon frequently asked questions
1. [Open TYPO3 tt_news Importer Issues](https://github.com/michael-cannon/typo3-importer/issues) - review and submit bug reports and enhancement requests
1. [TYPO3 tt_news Importer Support on WordPress](http://wordpress.org/support/plugin/typo3-importer) - ask questions and review responses
1. [Contribute Code to TYPO3 tt_news Importer](https://github.com/michael-cannon/typo3-importer/blob/master/CONTRIBUTING.md)
1. [Beta Testers Needed](http://store.axelerant.com/become-beta-tester/) - get the latest TYPO3 tt_news Importer version


== Screenshots ==

1. Where to find TYPO3 tt_news Importer in Tools
2. TYPO3 tt_news Importer settings
3. TYPO3 news entries being imported

[gallery]


== Changelog ==

See [CHANGELOG](https://github.com/michael-cannon/typo3-importer/blob/master/CHANGELOG.md)


== Upgrade Notice ==

= 0.1.0 =

* Initial release


== Notes ==

TBD


== Thank You ==

Current development by [Axelerant](https://axelerant.com/about-axelerant/).
