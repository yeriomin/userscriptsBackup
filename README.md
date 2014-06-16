userscriptsBackup
=================

userscripts.org backup script.

Saves all js scripts, their metadata, and a html description.

###Usage
`php userscriptsBackup.php`

Requires curl and libxml.

###How
Technically it uses multi CURL to get script list pages, rip script ids from them and then get the scripts themselves. Uses libxml to parse the pages.

It takes around 4 hours to get all the scripts. Pages/sources are downloaded in batches of 50. Downloading more items per batch or removing `sleep()`s triggers "Heavy load" errors.

On 16.06.2014 the whole archive had 392733 files and was 4.5Gb uncompressed and 700Mb compressed.

https://yadi.sk/d/TKECrmaPTajqq

###Example
For each script 3 files are saved:

1. 111111.js - script source
2. 111111.json - script title, summary, author, tags and other statistics
3. 111111.html - full description from the 


Example .json file:
```
{
    "id": 12301,
    "title": "IMDb->DirectSearch Fixed",
    "summary": "Fast & simple: Search directly from IMDb for reviews, torrents, subtitles and much more using movie title. It is now working with new IMDB (Jun 2011).",
    "rating": 5,
    "reviews": 1,
    "posts": 14,
    "fans": 7,
    "installs": 6233,
    "lastUpdated": 1308814569,
    "tags": [
        "allmovie",
        "Criticker",
        "Google for subtitles",
        "Google for torrents",
        "imdb",
        "imdb.com",
        "isohunt",
        "Mininova",
        "movies",
        "opensubtitles.org",
        "Piratebay",
        "Rotten Tomatoes",
        "Subscene.com",
        "subtitle",
        "torrent",
        "torrents",
        "Torrentz",
        "wikipedia"
    ],
    "authorId": 32431,
    "authorName": "mohanr",
    "partial": false,
    "grabTime": 1399046641
}
```
