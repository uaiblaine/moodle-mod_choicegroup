s/\n([ ]+)(\$transaction->allow_commit\(\);\n[ ]+\} catch)/\n$1choicegroup_release_locks(\$locks);\n$1\$locks = [];\n$1$2/ or die "s35 did not match";
