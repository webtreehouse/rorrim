 # rorrim
 #
 # Author: Chris Hagerman <chris@webtreehouse.com>
 # Copyright: Copyright (c) 2009 Chris Hagerman, released under the GPL.
 # License: GPL <http://www.gnu.org/licenses/gpl.html>
 # Version: 0.6
 #
 # Rorrim is a module capable of making a local copy of a website.

import logging
import hashlib
import os
import Queue
import re
import socket
import threading
import urllib2
import urlparse

LINK_TYPES = {
    "anchor" : '<\s*a[^\>]*(?P<location>href\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*).*?>)(?P<context>.*?)<\/a>',
    "image" : '<\s*(?P<location>img[^\>]*src\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))',
    "background" : '<\s*(?P<location>[body|table|th|tr|td][^\>]*background\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))',
    "input" : '<\s*input[^\>]*(?P<location>src\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))',
    "css" : '<\s*link[^\>]*stylesheet[^\>]*[^\>]*(?P<location>href\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))',
    "cssinvert" : '<\s*link[^\>]*(?P<location>href\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))[^\>]*stylesheet\s*',
    "cssimport" : '(?P<location>@\s*import\s*u*r*l*\s*[\""\'\(]?\s?(?P<url>[^\""\'\s\)\;>]*))',
    "cssurl" : '(?P<location>url\((?P<url>[^\)]+))',
    "javascript" : '<\s*script[^\>]*(?P<location>src\s*=\s*[\""\']?(?P<url>[^\""\'\s>]*))',
    }

MIME_TYPES = {
    "text/css" : ".css",
    "image/gif" : ".gif",
    "text/html" : ".html",
    "image/jpeg" : ".jpg",
    "application/x-javascript" : ".js",
    "image/png" : ".png",
    "text/plain" : ".txt",
    }

USER_AGENT = "Mozilla/5.0 (compatible; rorrim; +http://github.com/webtreehouse/rorrim/tree/master)"

logger = logging.getLogger("rorrim")

class Site:
    def __init__(self, source, destination, number_of_threads=5, time_out=20,
                 link_types=LINK_TYPES, mime_types=MIME_TYPES, user_agent=USER_AGENT,
                 log_level=logging.INFO):

        # setup logger config
        logging.basicConfig(level=log_level)
        logger.info("Mirroring " + source + " to " + destination)

        # set up socket timeout
        socket.setdefaulttimeout(time_out)

        # hang on to parameters
        self.source = source
        self.destination = destination
        self.number_of_threads = number_of_threads
        self.time_out = time_out
        self.link_types = link_types
        self.mime_types = mime_types
        self.user_agent = user_agent

        # setup list and queue the initial asset
        self.assets = dict()
        self.queue = Queue.Queue()
        self.add_asset(source=source, level=1, download=True)

        for i in range(number_of_threads):
            logger.debug("Starting worker thread #" + str(i + 1))
            t = threading.Thread(target=self.worker)
            t.setDaemon(True)
            t.start()

        self.queue.join()
        logger.debug("Queue is empty.")

        logger.debug("Updating links.")
        self.update_links()

        logger.debug("Saving assets.")
        self.save_assets()

        if self.assets.has_key(source):
            if self.assets[source].retrieved == True:
                self.primary_destination = self.assets[source].destination
                logger.info("Mirroring is complete.")
            else:
                logger.error("Mirroring has failed.")
        else:
            logger.error("Unknown error has occured.")

    def worker(self):
        while True:
            item = self.queue.get()
            logger.debug("Downloading " + item.source)
            try:
                item.download()
                logger.info("Downloaded " + item.source)
                self.assets[item.source] = item
                logger.debug("Getting linked assets in " + item.source)
                self.process_links(links=item.get_links(), level=item.level+1)
            except urllib2.HTTPError, e:
                logger.warning("Could not retrieve " + item.source + ". Got HTTP error code: " + str(e.code))
            except urllib2.URLError, e:
                logger.warning("Could not retrieve " + item.source + ". Server didn't respond.")
            except:
				logger.error("Unhandled error while retrieving " + item.source + ".")
            finally:
                self.queue.task_done()

    def add_asset(self, source, level, download=False):
        if not self.assets.has_key(source):
            logger.debug("Found " + source + ". Level #" + str(level))
            asset = Asset(source=source, destination=self.destination, level=level, link_types=self.link_types, mime_types=self.mime_types, user_agent=self.user_agent)
            self.assets[source] = asset
            if download == True:
                logger.debug("Queued " + source)
                self.queue.put(asset)

    def process_links(self, links, level):
        for link in links:
            if link.relation != "anchor" and level < 10:
                self.add_asset(source=link.source, level=level, download=True)
            else:
                self.add_asset(source=link.source, level=level, download=False)

    def update_links(self):
        for source, asset in self.assets.iteritems():
            logger.debug("Updating links in " + source)
            asset.update_links(self.assets)

    def save_assets(self):
        for source, asset in self.assets.iteritems():
            if asset.retrieved == True:
                logger.debug("Saving " + source)
                if asset.save() == False:
                    logger.warning("Save operation failed for " + asset.source + ". Unrecognized MIME type (" + asset.content_type + ").")
    
class Asset:
    def __init__(self, source, destination, level, link_types, mime_types, user_agent):
        self.source = source
        self.destination = destination
        self.level = level
        self.links = list()
        self.link_types = link_types
        self.mime_types = mime_types
        self.user_agent = user_agent
        self.retrieved = False
        self.url = source

    def download(self):
        if self.retrieved == False:
            # grab the data
            headers = { "User-Agent" : self.user_agent }
            request = urllib2.Request(self.source, None, headers)
            response = urllib2.urlopen(request)
            info = response.info()

            self.content_type = info.get("Content-Type")
            self.data = response.read()
            self.source = response.geturl()

            # update page base for resolving relational links
            self.base = self.get_base()

            # update destination and url for linking
            filename = self.get_filename()

            if filename != False:
                self.url = filename
                self.destination = os.path.join(self.destination, filename)
                self.retrieved = True

    def get_base(self):
        base_tag = re.compile('<\s*base[^\>]*href\s*=\s*[\""\']?([^\""\'\s>]*).*?>', re.IGNORECASE | re.MULTILINE)
        match = base_tag.search(self.data)
        if match:
            self.data = self.data.replace(match.group(0), "")
            if match.group(1).endswith("/"):
                return match.group(1)
            else:
                return match.group(1) + "/"
        else:
            parts = urlparse.urlsplit(self.source, "http", False)
            if parts:
                if not parts[2].endswith("/"):
                    base = parts[0], parts[1], parts[2][:parts[2].rfind("/")+1], "", ""
                else:
                    base = parts[0], parts[1], parts[2], "", ""
                return urlparse.urlunsplit(base)
            else:
                logger.warning("Couldn't detect page base in: " + self.source + ". URL appears to be malformed.")
                return False

    def get_filename(self):
        # strip any encoding off of the content-type string for matching against
        # the mime type dictionary
        content_type = self.content_type.split(";")[0]

        if self.mime_types.has_key(content_type):
            filename = hashlib.md5(self.source).hexdigest()
            filename += self.mime_types[content_type]
            return filename
        else:
            return False

    def get_page_title(self):
		# not implemented yet
        pass

    def get_links(self):
        links = list()
        for relation, regex in self.link_types.iteritems():
            pattern = re.compile(regex, re.IGNORECASE | re.MULTILINE)
            for match in pattern.finditer(self.data):
                matches = match.groupdict()

                link = Link()

                if matches.has_key("context"): link.context = matches["context"]
                link.location = matches["location"]
                link.relation = relation
                link.source = urlparse.urljoin(self.base, matches["url"].strip("\"' "), False)
                link.original_source = matches["url"]

                links.append(link)

        self.links = links
        return links

    def update_links(self, assets):
        for link in self.links:
            if assets.has_key(link.source):
                if link.location and link.original_source and assets[link.source].url:
                    self.data = self.data.replace(link.location, link.location.replace(link.original_source, assets[link.source].url))

    def save(self):
        if self.retrieved == True:
            file = open(self.destination, 'w')
            file.write(self.data)
            file.close()

            logger.debug("Saved " + self.source + " as " + self.destination)
            return True
        else:
            return False

class Link:
    pass