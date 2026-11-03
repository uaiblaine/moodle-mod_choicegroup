// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Shows and hides the group members and group descriptions on the view page.
 *
 * @module      mod_choicegroup/choicegroupdatadisplay
 * @copyright   2018 Université de Lausanne
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/str'], function($, str) {
    return {
        init: function() {
            $('.choicegroup-memberdisplay').click(function(e) {
                e.preventDefault();
                $('.choicegroups-membersnames').toggleClass('hidden');
                var showusersstring = str.get_string('showgroupmembers', 'mod_choicegroup');
                var hideusersstring = str.get_string('hidegroupmembers', 'mod_choicegroup');
                if ($('.choicegroups-membersnames').is(":visible")) {
                    $.when(hideusersstring).done(function(hidestring) {
                        $(".choicegroup-memberdisplay").html(hidestring);
                    });
                } else {
                    $.when(showusersstring).done(function(showstring) {
                        $(".choicegroup-memberdisplay").html(showstring);
                    });
                }
            });

            $('.choicegroup-descriptiondisplay').click(function(e) {
                e.preventDefault();
                $('.choicegroups-descriptions').toggleClass('hidden');
                var hidedescriptionstring = str.get_string('hidedescription', 'mod_choicegroup');
                var showdescriptionstring = str.get_string('showdescription', 'mod_choicegroup');
                if ($('.choicegroups-descriptions').is(":visible")) {
                    $.when(hidedescriptionstring).done(function(hidestring) {
                        $(".choicegroup-descriptiondisplay").html(hidestring);
                    });
                } else {
                    $.when(showdescriptionstring).done(function(showstring) {
                        $(".choicegroup-descriptiondisplay").html(showstring);
                    });
                }
            });
        }
    };

});
