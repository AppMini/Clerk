Create a new CSV with a header:

	curl 'http://localhost:8000/server/index.php' -H 'Content-Type: application/x-www-form-urlencoded' --data "event=mylog&create=1"

Two ways to post a timestamped entry to a CSV:

	bin/test-post.sh mylog
	
	curl 'http://localhost:8000/server/index.php' -H 'Content-Type: application/x-www-form-urlencoded' --data "event=mylog&timestamp=2017-02-11 15:55:48"

Two ways to post a timestamped entry with a comment to a CSV:

	bin/test-post.sh mylog Some comment or extra data.
	
	curl 'http://localhost:8000/server/index.php' -H 'Content-Type: application/x-www-form-urlencoded' --data "event=mylog&timestamp=2017-02-11 15:55:48&comment=Some comment or extra data."

Fetch a particular CSV:

	curl 'http://localhost:8000/data/mylog.csv'

Fetch all CSVs concatenated together:

	bin/test-fetch-csvs.sh
	
	curl 'http://localhost:8000/server/index.php?all'

