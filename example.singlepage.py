#!/usr/bin/env python

# rorrim
#
# Author: Chris Hagerman <chris@webtreehouse.com>
# Copyright: Copyright (c) 2009 Chris Hagerman, released under the GPL.
# License: GPL <http://www.gnu.org/licenses/gpl.html>
# Version: 0.6
#
# This example shows how to save a single page and its images, css, and javascript to the output directory.

import urlparse
import rorrim

s = rorrim.Site(source="http://github.com/", destination="output")
print "Output has been saved to", s.home.destination