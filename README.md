# AiTag

I wrote this to help tag resources in AWS automatically.

## Prerequisites

* [AWS CLI tool](https://aws.amazon.com/cli/)
  * and a **read-only** profile configured in your `~/.aws/credentials` file
  * this is not enforced (no policy check or anything)
* Local PHP
* A mySql DB server where you can create a DB

## Set up

### Prep
* Copy `.env.example` and replace vars
  * the pre-populated ones are potentially reusable
  * the empty ones are specific to your use or secret
* Create a DB and use `migrations/ddl.sql` to create the tables.
* Run `composer install`

### Test sample
* Populate your `taggables` table with the file in `data/pmrc-billboard-sample.csv`
* Run `php ./aiTag.php` to test against sample data

### Run
* Populate your `taggables` table with a real file, a csv export of your DevOps provide AWS resource list
* Run `php ./aiTag.php` to start tagging!

## Next Steps

* Move prompts to include-able files (something like [dataprompt](https://github.com/davideast/dataprompt)?)
* More parameterizing - there is still MRC specific stuff
