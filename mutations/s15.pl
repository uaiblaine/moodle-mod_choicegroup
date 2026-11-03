s/        if \(!\$lock\) \{\n            choicegroup_release_locks\(\$locks\);\n            throw new moodle_exception\(\x27locktimeout\x27\);\n        \}\n//  or die "s15 did not match";
