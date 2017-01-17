=== Gravity Forms Eloqua ===
Tags: gravity forms, forms, gravity, form, crm, gravity form, mail, email, newsletter, Eloqua, Oracle, plugin, sidebar, widget, mailing list, API, email marketing, newsletters
Requires at least: 2.8
Tested up to: 4.5.1
Stable tag: trunk
Contributors: briandichiara
Donate link: https://www.paypal.me/briandichiara

Integrate Eloqua Forms with Gravity Forms

== Description ==

> This plugin requires an <a href="https://eloqua.com/" rel="nofollow">Eloqua</a> account.

###Integrate Eloqua with Gravity Forms
If you use <strong>Eloqua</strong> email service and the Gravity Forms plugin, you're going to want this plugin!

Integrate your Gravity Forms forms so that when users submit a form entry, the entries get added to Eloqua. Link any field type with Eloqua, including custom fields!

== Installation ==
1. Upload plugin files to your plugins folder, or install using WordPress' built-in Add New Plugin installer.
1. Activate the plugin
1. Go to the plugin settings page (under Forms > Settings > Eloqua)
1. Enter the information requested by the plugin.
1. Click Save Settings.
1. If the settings are correct, it will say so.
1. Follow on-screen instructions for integrating with Eloqua.

For Automatic Updates, install the GitHub-Updater plugin: https://github.com/afragen/github-updater

== Frequently Asked Questions ==
Nobody has asked any questions frequently.

== Screenshots ==
No screenshots at this time.

== Upgrade Notice ==
= 1.5.2 =
* Fixed a PHP warning which would occur when the entry meta was being processed during submission.

= 1.5.1 =
* Fixed a bug when refreshing OAuth token
* FIxed a bug with admin notification

= 1.5.0 =
* Completely revamped Debugger/Entry Notes with a Custom Debugger Class
* Added additional debugging info to various places
* Attempted to fix false positives
* Added a button to reset the entry status in the case of a false positive, so a resubmission can be done and debug notes can be reviewed.
* Removed some duplicate debugging comments
* Fixed a few typos
* Added visual queue of unlimited retries.

= 1.4.2 =
* Removed Github Access Token

= 1.4.1 =
* Added automatic re-submission of failed entries
* Added display count to show retry attempts.
* Added private Github Updater Token, but I don't think it's working.

= 1.4.0 =
* Restructured repository for Github Updater support

= 1.3.3 =
* Added "Retry Submission" button on failed submissions to Eloqua
* Added "Sent to Eloqua?" meta column to display submissions status on Entries View
* Added additional debug detail when submissions fail to be received by Eloqua

= 1.3.2 =
* Fixed bug where form list from Eloqua wouldn't refresh with latest forms

= 1.3.1 =
* Fixed PHP Notice when inserting version data throws notice about non-object
* Added GitHub Updater plugin support (More Info: https://github.com/afragen/github-updater)
* Added filter `gfeloqua_validate_response` to validate_response in GFEloqua API Class
* Added entry note/error logging and display in admin
* Updated select2 to version 4.0.3

= 1.3.0 =
* Fixed bug where only 1000 records are displayed. (needs testing)
* Fixed bug where multi-checkbox values are not being stored.
* Added feature to show Forms grouped by folder name
* Added ability to specify count and page parameters to get_forms() method
* Added Admin Notice when Eloqua is disconnected
* A few minor tweaks

= 1.2.4 =
* fixed a bug keeping you from disabling the notification
* added some documentation

= 1.2.3 =
* added feature to alert you if Eloqua is disconnected

= 1.2.2 =
* added better OAuth setup, no longer needs code copy/paste
* added better error message when can't connect to Eloqua

= 1.2.1 =
* added select2 to find Eloqua forms easier
* fixed javascript spinner bug

= 1.2.0 =
* NOTE: Changed plugin slug to fix Issue #4. Your settings may need to be reset.
* added OAuth support
* added credential validation to settings page
* fixed Issue #4 Gravity Forms Registration Warning
* fixed Issue #5 Error "This add-on needs to be updated. Please contact the developer."

= 1.1.0 =
* setup securely stored auth string
* fixed bug with clearing transients
* minor bug fixes

= 1.0.1 =
* Added refresh buttons to clear transients

= 1.0 =
* Launched plugin
