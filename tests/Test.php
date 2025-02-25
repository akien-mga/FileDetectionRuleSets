<?php
declare(strict_types=1);

require __DIR__ . '/FileDetector.php';

$FailingTests = [];
$Rulesets = parse_ini_file( __DIR__ . '/../rules.ini', true, INI_SCANNER_RAW );

if( empty( $Rulesets ) )
{
	throw new \RuntimeException( 'rules.ini failed to parse' );
}

foreach( $Rulesets as $Type => $Rules )
{
	$SortTest = TestSorting( $Rules );

	if( $SortTest !== null )
	{
		$FailingTests[] = "{$Type}: Rules should be sorted in case insensitive natural order, {$SortTest}";
	}

	foreach( $Rules as $Name => $RuleRegexes )
	{
		if( !is_array( $RuleRegexes ) )
		{
			$RuleRegexes = [ $RuleRegexes ];
		}
		else if( count( $RuleRegexes ) === 1 )
		{
			$FailingTests[] = "$Type.$Name is an array for no reason, remove []";
		}

		foreach( $RuleRegexes as $Regex )
		{
			if( RegexHasCapturingGroups( $Regex ) )
			{
				$FailingTests[] = "$Type.$Name: Regex \"$Regex\" contains a capturing group";
			}
		}
	}
}

$Detector = new FileDetector( $Rulesets, null );
$Detector->FilterEvidenceMatches = false;

$TestsIterator = new DirectoryIterator( __DIR__ . '/types' );
$SeenTestTypes = [];

foreach( $TestsIterator as $File )
{
	if( $File->getExtension() !== 'txt' )
	{
		continue;
	}

	$TestFilePaths = file( $File->getPathname(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	$ExpectedType = $File->getBasename( '.txt' );

	if( $ExpectedType === '_NonMatchingTests' )
	{
		$ExpectedType = null;
	}

	$AlreadySeenStrings = [];

	foreach( $TestFilePaths as $Path )
	{
		if( isset( $AlreadySeenStrings[ $Path ] ) )
		{
			$FailingTests[] = "Path \"$Path\" in \"$File\" is defined more than once";
			continue;
		}

		$AlreadySeenStrings[ $Path ] = true;

		$Actual = $Detector->GetMatchesForFileList( [ $Path ] );

		if( preg_last_error() !== PREG_NO_ERROR )
		{
			err( 'Regex is failing: ' . preg_last_error_msg() );
			exit( 2 );
		}

		if( $ExpectedType === null )
		{
			if( empty( $Actual ) )
			{
				continue;
			}

			foreach( $Actual as $Match => $Count )
			{
				if( str_starts_with( $Match, 'Evidence.' ) )
				{
					// Evidence tests get ignored when matching non-matching tests
					continue;
				}
				else
				{
					$FailingTests[] = "Path \"$Path\" returned \"$Match\" but it should not have matched anything";
				}
			}
		}
		else
		{
			if( isset( $Actual[ $ExpectedType ] ) )
			{
				continue;
			}

			$FailingTests[] = "Path \"$Path\" does not match for \"$ExpectedType\"";
		}
	}

	if( !empty( $TestFilePaths ) )
	{
		$SeenTestTypes[ $ExpectedType ] = true;
	}
}

foreach( array_unique( $Detector->Map ) as $TestType )
{
	if( !isset( $SeenTestTypes[ $TestType ] ) )
	{
		$FailingTests[] = "\"$TestType\" does not have any tests";
	}

	$File = __DIR__ . '/../descriptions/' . $TestType . '.md';

	if( !file_exists( $File ) )
	{
		$FailingTests[] = "\"descriptions/{$TestType}.md\" does not exist";
	}
}

if( !empty( $FailingTests ) )
{
	echo count( $FailingTests ) . " tests failed.\n";

	foreach( $FailingTests as $Test )
	{
		err( $Test );
	}

	exit( 1 );
}
else
{
	echo "All tests have passed.\n";
}

RunTwoPassTest( $Detector );

function RegexHasCapturingGroups( string $regex ) : bool
{
	// From https://github.com/nikic/FastRoute/blob/dafa1911fd7c1560c64d19556cbd4c599fed15ea/src/DataGenerator/RegexBasedAbstract.php#L181
	if( strpos( $regex, '(' ) === false )
	{
		// Needs to have at least a ( to contain a capturing group
		return false;
	}

	// Semi-accurate detection for capturing groups
	return (bool)preg_match(
		'~
			(?:
				\(\?\(
				| \[ [^\]\\\\]* (?: \\\\ . [^\]\\\\]* )* \]
				| \\\\ .
			) (*SKIP)(*FAIL) |
			\(
			(?!
				\? (?! <(?![!=]) | P< | \' )
				| \*
			)
		~x',
		$regex
	);
}

function TestSorting( array $Rulesets ) : ?string
{
	$Sorted = $Rulesets;

	uksort( $Sorted, fn( string $a, string $b ) : int => strnatcasecmp( $a, $b ) );

	if( $Rulesets !== $Sorted )
	{
		$gamesKeys = array_keys( $Rulesets );
		$gamesSortedKeys = array_keys( $Sorted );
		$cachedCount = count( $gamesKeys );

		for( $i = 0; $i < $cachedCount; ++$i )
		{
			if( $gamesKeys[ $i ] === $gamesSortedKeys[ $i ] )
			{
				continue;
			}

			$sortedPosition = array_search( $gamesKeys[ $i ], $gamesSortedKeys );
			$actualPosition = array_search( $gamesSortedKeys[ $i ], $gamesKeys );
			$shouldBe = $gamesSortedKeys[ $sortedPosition - 1 ];

			if( $actualPosition > $sortedPosition )
			{
				return "\"{$shouldBe}\" should be before \"{$gamesKeys[ $i ]}\"";
			}

			return "\"{$gamesKeys[ $i ]}\" should be after \"{$shouldBe}\"";
		}
	}

	return null;
}

function RunTwoPassTest( FileDetector $Detector )
{
	require __DIR__ . '/Test2Pass.php';
}

function err( string $Message ) : void
{
	if( getenv( 'CI' ) !== false )
	{
		echo "::error::" . $Message . PHP_EOL;
	}
	else
	{
		fwrite( STDERR, $Message . PHP_EOL );
	}
}
