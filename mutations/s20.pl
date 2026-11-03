s/(case GROUPS_VISIBILITY_MEMBERS:\n            return )groups_is_member\(\$group->id, \$USER->id\) \? \$members : \[\];/$1\$members;/ or die "s20 did not match";
