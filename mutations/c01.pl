s/CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE && \$closed\)/CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE \&\& (\$closed || time() < \$choicegroup->timeopen))/ or die "c01 did not match";
