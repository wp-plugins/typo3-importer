=== TYPO3 Importer ===
Contributors: comprock
Donate link: http://peimic.com/about-peimic/donate/
Tags: typo3, importer
Requires at least: 3.0.0
Tested up to: 3.2.1
Stable tag: 1.0.0

Import tt_news and tx_comments from TYPO3 into WordPress.

== Description ==

Simple importer to bring your TYPO3 news and their related media and comments into WordPress. Modeled after livejournal-importer.

== Installation ==

1. Upload the `typo3-importer` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to the Tools -> Import screen, Click on TYPO3 Importer

== Frequently Asked Questions ==

= Can I sponsor importing TYPO3 pages? =

Yes. Any sponsoring would be greatly welcome. Please [donate](http://peimic.com/about-peimic/donate/ "Help sponsor TYPO3 Importer") and let me know what's wanted

== Screenshots ==
1. Where to find TYPO3 Importer in Tools
2. TYPO3 Importer settings
3. TYPO3 news entries being imported
4. TYPO3 comment entries being imported

== Changelog ==

= 0.1.1 =
* set featured image from content source or related images
* seperate news/comment batch limits
* CamelCase to under_score
* rename batch limit var
* lower batch limit further, serious hang 10 when doing live imports
* lower batch limit due to seeming to hang
* correct plugin url
* revise recognition
* Validate readme.txt
* Inital import of "languages" directory
* add license; enable l18n

= 0.1.0 =
* Initial release

== Upgrade Notice ==
* None
