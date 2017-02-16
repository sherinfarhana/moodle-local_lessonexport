Lesson export
===========

This plugin adds the ability to export Moodle lessons as PDF documents.
Many thanks to Davo Smith for developing the original base-code this plugin was ported from.

Usage
=====

Once the plugin is installed, you can visit a lesson, then click on the new 'Export as PDF' link that appears
in the activity administration block (with javascript enabled, similar links are inserted on the top-right corner of the page).

There is an additional global setting which allows a copy of any lessons on the site to be sent (as a PDF) to a given email address,
whenever they are updated (note, this will not export all lessons on the site the first time it is configured, it only sends those
that have been updated since the email address was first entered).

There is also a global setting to define additional custom style rules that will take effect only in the exported document.

Customising
===========

If you want to add your organisation's logo to the front page of the exported lesson, please replace the file
local/lessonexport/pix/logo.png with your logo. Do not alter the file dimensions, it must remain 514 by 182 pixels.

Customise the following language strings, to alter the embedded export information:
'publishername' - set the PDF 'publisher' field
'printed' - set the description on the front page 'This doucment was downloaded on [date]'

(see https://docs.moodle.org/en/Language_customization for more details)

Contact
=======

Any enquiries should be sent to devadamking@gmail.com
