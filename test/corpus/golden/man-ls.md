[_LS_(1)](https://www.chedong.com/phpMan.php/man/LS/1/markdown)                                       User Commands                                      [_LS_(1)](https://www.chedong.com/phpMan.php/man/LS/1/markdown)

## NAME
       ls - list directory contents

## SYNOPSIS
       **ls **[_OPTION_]... [_FILE_]...

## DESCRIPTION
       List information about the FILEs (the current directory by default).  Sort entries alphabeti‐
       cally if none of **-cftuvSUX **nor **--sort **is specified.

       Mandatory arguments to long options are mandatory for short options too.

### -a --all
              do not ignore entries starting with .

### -A --almost-all
              do not list implied . and ..

### --author
              with **-l**, print the author of each file

### -b --escape
              print C-style escapes for nongraphic characters

       **--block-size**=_SIZE_
              with **-l**, scale sizes by SIZE when printing them; e.g., '--block-size=M'; see SIZE for‐
              mat below

### -B --ignore-backups
              do not list implied entries ending with ~

### -c      -lt
              with **-l**: show ctime and sort by name; otherwise: sort by ctime, newest first

### -C     

       **--color**[=_WHEN_]
              color the output WHEN; more info below

### -d --directory
              list directories themselves, not their contents

### -D --dired
              generate output designed for Emacs' dired mode

### -f     

### -F --classify
              append indicator (one of */=>@|) to entries WHEN

### --file-type
              likewise, except do not append '*'

       **--format**=_WORD_
              across **-x**, commas **-m**, horizontal **-x**, long **-l**, single-column **-1**, verbose  **-l**,  vertical
              **-C**

### --full-time
              like **-l --time-style**=_full-iso_

### -g      -l

### --group-directories-first
              group  directories before files; can be augmented with a **--sort **option, but any use of
              **--sort**=_none_ (**-U**) disables grouping

### -G --no-group
              in a long listing, don't print group names

### -h --human-readable
              with **-l **and **-s**, print sizes like 1K 234M 2G etc.

       **--si   **likewise, but use powers of 1000 not 1024

### -H --dereference-command-line
              follow symbolic links listed on the command line

### --dereference-command-line-symlink-to-dir
              follow each command line symbolic link that points to a directory

       **--hide**=_PATTERN_
              do not list implied entries matching shell PATTERN (overridden by **-a **or **-A**)

       **--hyperlink**[=_WHEN_]
              hyperlink file names WHEN

       **--indicator-style**=_WORD_
              append indicator with style WORD to entry names: none (default), slash (**-p**), file-type
              (**--file-type**), classify (**-F**)

### -i --inode
              print the index number of each file

### -I --ignore
              do not list implied entries matching shell PATTERN

### -k --kibibytes
              default to 1024-byte blocks for file system usage; used only with **-s **and per directory
              totals

### -l     

### -L --dereference
              when showing file information for a symbolic link, show information for the  file  the
              link references rather than for the link itself

### -m     

### -n --numeric-uid-gid
              like **-l**, but list numeric user and group IDs

### -N --literal
              print entry names without quoting

### -o      -l

### -p --indicator-style
              append / indicator to directories

### -q --hide-control-chars
              print ? instead of nongraphic characters

### --show-control-chars
              show  nongraphic characters as-is (the default, unless program is 'ls' and output is a
              terminal)

### -Q --quote-name
              enclose entry names in double quotes

       **--quoting-style**=_WORD_
              use quoting  style  WORD  for  entry  names:  literal,  locale,  shell,  shell-always,
              shell-escape,  shell-escape-always,  c,  escape  (overrides  QUOTING_STYLE environment
              variable)

### -r --reverse
              reverse order while sorting

### -R --recursive
              list subdirectories recursively

### -s --size
              print the allocated size of each file, in blocks

### -S     

       **--sort**=_WORD_
              sort by WORD instead of name: none (**-U**), size (**-S**), time (**-t**), version (**-v**), extension
              (**-X**), width

       **--time**=_WORD_
              select which timestamp used to display or sort; access time (**-u**): atime, access,  use;
              metadata  change  time  (**-c**): ctime, status; modified time (default): mtime, modifica‐
              tion; birth time: birth, creation;

              with **-l**, WORD determines which time to show; with **--sort**=_time_, sort  by  WORD  (newest
              first)

       **--time-style**=_TIME_STYLE_
              time/date format with **-l**; see TIME_STYLE below

### -t      --time

### -T --tabsize
              assume tab stops at each COLS instead of 8

### -u      -lt -l
              otherwise: sort by access time, newest first

### -U     

### -v     

### -w --width
              set output width to COLS.  0 means no limit

### -x     

### -X     

### -Z --context
              print any security context of each file

       **--zero **end each output line with NUL, not newline

### -1     

       **--help **display this help and exit

### --version
              output version information and exit

       The SIZE argument is an integer and optional unit  (example:  10K  is  10*1024).   Units  are
       K,M,G,T,P,E,Z,Y,R,Q  (powers  of 1024) or KB,MB,... (powers of 1000).  Binary prefixes can be
       used, too: KiB=K, MiB=M, and so on.

       The TIME_STYLE argument can be full-iso, long-iso, iso, locale, or +FORMAT.  FORMAT is inter‐
       preted like in [**date**(1)](https://www.chedong.com/phpMan.php/man/date/1/markdown).  If  FORMAT  is  FORMAT1<newline>FORMAT2,  then  FORMAT1  applies  to
       non-recent files and FORMAT2 to recent files.  TIME_STYLE prefixed with 'posix-' takes effect
       only  outside  the  POSIX  locale.  Also the TIME_STYLE environment variable sets the default
       style to use.

       The WHEN argument defaults to 'always' and can also be 'auto' or 'never'.

       Using color to distinguish file types is disabled both by  default  and  with  **--color**=_never_.
       With **--color**=_auto_, ls emits color codes only when standard output is connected to a terminal.
       The  LS_COLORS environment variable can change the settings.  Use the [**dircolors**(1)](https://www.chedong.com/phpMan.php/man/dircolors/1/markdown) command to
       set it.

### Exit status:
       0      if OK,

       1      if minor problems (e.g., cannot access subdirectory),

       2      if serious trouble (e.g., cannot access command-line argument).

## AUTHOR
       Written by Richard M. Stallman and David MacKenzie.

## REPORTING BUGS
       GNU coreutils online help: <<https://www.gnu.org/software/coreutils/>>
       Report any translation bugs to <<https://translationproject.org/team/>>

## COPYRIGHT
       Copyright © 2023 Free Software Foundation, Inc.  License GPLv3+: GNU GPL version 3  or  later
       <<https://gnu.org/licenses/gpl.html>>.
       This  is free software: you are free to change and redistribute it.  There is NO WARRANTY, to
       the extent permitted by law.

## SEE ALSO
       [**dircolors**(1)](https://www.chedong.com/phpMan.php/man/dircolors/1/markdown)

       Full documentation <<https://www.gnu.org/software/coreutils/ls>>
       or available locally via: info '(coreutils) ls invocation'

GNU coreutils 9.4                           January 2026                                       [_LS_(1)](https://www.chedong.com/phpMan.php/man/LS/1/markdown)
