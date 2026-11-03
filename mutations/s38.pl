s/(\/\/ After the commit or the rollback[^\n]*\n[ ]+)choicegroup_release_locks\(\$locks\);/$1if (empty(\$e)) {\n    choicegroup_release_locks(\$locks);\n}/ or die "s38 did not match";
