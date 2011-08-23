=== TYPO3 Importer ===
Contributors: comprock
Donate link: http://peimic.com/about-peimic/donate/
Tags: typo3, importer
Requires at least: 3.0.0
Tested up to: 3.2.1
Stable tag: 1.0.3

Import tt_news and tx_comments from TYPO3 into WordPress.

== Description ==
Importer to bring your TYPO3 news and their related media and comments into WordPress.

Requires remote web and database access to the source TYPO3 instance. Images, files and comments related to TYPO3 tt_news entries will be pulled into WordPress as new posts.

Inline and related images will be added to the Media Library. The first image found will be set as the Featured Image for the post. Inline images will have their source URLs updated. Related images will be converted to a [gallery] and inserted into the post about 2 paragraphs in.

Files will be appended to post content as 'Related Files'.

It's possible to change post statuses on import. However, draft posts, will remain as drafts.

If you accidentially import TYPO3 news and they've gone live, visit the importer and look for `Oops`. There's an option to convert imported posts to `Private` thereby removing from public view.

Finally, it's possible to delete prior imports and lost comments and attachments.

TYPO3 Importer was modeled after the livejournal-importer plugin.

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
= trunk =
* Add askimet_spam_checker to comment importing
-

= 1.0.2 =
* Update description

= 1.0.1 =
* Update Changelog

= 1.0.0 =
* Update TYPO3 Importer settings screenshot
* update CHANGELOG
* Add force_private_posts(), Great for when you accidentially push imports live, but didn't mean to;
* Remove excess options labels
* fix options saving
* Force post status save as option; Select draft, publish and future statuses from news import; Set input defaults;
* Clarify plugin description; Add datetime to custom data; remove user_nicename as it prevents authors URLs from working;
* remove testing case
* prevent conflicting usernames
* update Peimic.com plugin URL

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
