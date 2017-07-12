define(['jquery'], function($) {

    return {
        init: function(links) {
            var $el, i

            // Find the right place in the DOM to add the links.
            $parent = $('#region-main')
            $target = $parent.find('[role="main"]')

            for (i in links) {
                if (!links.hasOwnProperty(i)) {
                    continue;
                }

                $target.prepend(links[i]);
            }
        }
    }
})