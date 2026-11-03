s/(choicegroup_purge_request_cache\(\);\n)        \$transaction->rollback\(\$e\);/$1        \$transaction->allow_commit();\n        throw \$e;/ or die "s34 did not match";
