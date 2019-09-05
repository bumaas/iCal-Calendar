<?php
/**
 * iCalcreator, the PHP class package managing iCal (rfc2445/rfc5445) calendar information.
 *
 * copyright (c) 2007-2019 Kjell-Inge Gustafsson, kigkonsult, All rights reserved
 * Link      https://kigkonsult.se
 * Package   iCalcreator
 * Version   2.29.10
 * License   Subject matter of licence is the software iCalcreator.
 *           The above copyright, link, package and version notices,
 *           this licence notice and the invariant [rfc5545] PRODID result use
 *           as implemented and invoked in iCalcreator shall be included in
 *           all copies or substantial portions of the iCalcreator.
 *
 *           iCalcreator is free software: you can redistribute it and/or modify
 *           it under the terms of the GNU Lesser General Public License as published
 *           by the Free Software Foundation, either version 3 of the License,
 *           or (at your option) any later version.
 *
 *           iCalcreator is distributed in the hope that it will be useful,
 *           but WITHOUT ANY WARRANTY; without even the implied warranty of
 *           MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *           GNU Lesser General Public License for more details.
 *
 *           You should have received a copy of the GNU Lesser General Public License
 *           along with iCalcreator. If not, see <https://www.gnu.org/licenses/>.
 *
 * This file is a part of iCalcreator.
 */

namespace Kigkonsult\Icalcreator\Util;

use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Kigkonsult\Icalcreator\Vcalendar;
use RuntimeException;
use UnexpectedValueException;

use function arsort;
use function key;
use function reset;
use function sprintf;
use function str_replace;
use function strlen;
use function strpos;
use function timezone_abbreviations_list;
use function timezone_name_from_abbr;
use function trim;

/**
 * Class RegulateTimezoneFactory
 *
 * Review timezones, opt. alter to PHP timezones
 * @see https://docs.microsoft.com/en-us/windows-hardware/manufacture/desktop/default-time-zones
 * Cover Vtimezone property TZID and component date properties DTSTART, DTEND, DUE, RECURRENCE_ID
 *
 * @author      Kjell-Inge Gustafsson <ical@kigkonsult.se>
 * @since  2.29.10 - 2019-09-02
 * @todo properties RDATE, EXDATE, RRULE, EXRULE, FREEBUSY.. ??
 */
class RegulateTimezoneFactory
{
    /**
     * Add MS timezone and offset to (internal) MStimezoneToOffset
     *
     * @param string $msTz
     * @param string $offset   (+/-)HH:mm
     * @static
     */
    public static function addMStimezoneToOffset( $msTz, $offset ) {
        self::$MStimezoneToOffset[$msTz] = $offset;
    }

    /**
     * 4 GMT/UTC(-suffixed)
     * 59 matches on NOT dst, OK
     * 5 hits on dst (aka daylight saving time)...
     * 5 PHP timezones specified
     *
     * @var array  MS timezones with corr. UTC offset, 73 items
     * @static
     */
    public static $MStimezoneToOffset = [
        'Afghanistan Standard Time'       => '+04:30',
        'Arab Standard Time'              => '+03:00',
        'Arabian Standard Time'           => '+04:00',
        'Arabic Standard Time'            => '+03:00',
        'Argentina Standard Time'         => '-03:00',
        'Atlantic Standard Time'          => '-04:00',
        'AUS Eastern Standard Time'       => '+10:00',
        'Azerbaijan Standard Time'        => '+04:00',
        'Bangladesh Standard Time'        => '+06:00',
        'Belarus Standard Time'           => '+03:00',
        'Cape Verde Standard Time'        => '-01:00',
        'Caucasus Standard Time'          => '+04:00',
        'Central America Standard Time'   => '-06:00',
        'Central Asia Standard Time'      => '+06:00',
        'Central Europe Standard Time'    => '+01:00',
        'Central European Standard Time'  => '+01:00',
        'Central Pacific Standard Time'   => '+11:00',
        'Central Standard Time (Mexico)'  => '-06:00',
        'China Standard Time'             => '+08:00',
        'E. Africa Standard Time'         => '+03:00',
        'E. Europe Standard Time'         => '+02:00',
        'E. South America Standard Time'  => '-03:00',
        'Eastern Standard Time'           => '-05:00',
        'Egypt Standard Time'             => '+02:00',
        'Fiji Standard Time'              => '+12:00',
        'FLE Standard Time'               => '+02:00',
        'Georgian Standard Time'          => '+04:00',
        'GMT Standard Time'               => '',
        'Greenland Standard Time'         => '-03:00',
        'Greenwich Standard Time'         => '',
        'GTB Standard Time'               => '+02:00',
        'Hawaiian Standard Time'          => '-10:00',
        'India Standard Time'             => '+05:30',
        'Israel Standard Time'            => '+02:00',
        'Jordan Standard Time'            => '+02:00',
        'Korea Standard Time'             => '+09:00',
        'Mauritius Standard Time'         => '+04:00',
        'Middle East Standard Time'       => '+02:00',
        'Montevideo Standard Time'        => '-03:00',
        'Morocco Standard Time'           => '',
        'Myanmar Standard Time'           => '+06:30',
        'Namibia Standard Time'           => '+01:00',
        'Nepal Standard Time'             => '+05:45',
        'New Zealand Standard Time'       => '+12:00',
        'Pacific SA Standard Time'        => '-03:00',
        'Pacific Standard Time'           => '-08:00',
        'Pakistan Standard Time'          => '+05:00',
        'Paraguay Standard Time'          => '-04:00',
        'Romance Standard Time'           => '+01:00',
        'Russian Standard Time'           => '+03:00',
        'SA Eastern Standard Time'        => '-03:00',
        'SA Pacific Standard Time'        => '-05:00',
        'SA Western Standard Time'        => '-04:00',
        'Samoa Standard Time'             => '+13:00',
        'SE Asia Standard Time'           => '+07:00',
        'Singapore Standard Time'         => '+08:00',
        'South Africa Standard Time'      => '+02:00',
        'Sri Lanka Standard Time'         => '+05:30',
        'Syria Standard Time'             => '+02:00',
        'Taipei Standard Time'            => '+08:00',
        'Tokyo Standard Time'             => '+09:00',
        'Tonga Standard Time'             => '+13:00',
        'Turkey Standard Time'            => '+02:00',
        'Ulaanbaatar Standard Time'       => '+08:00',
        'UTC'                             => '',
        'UTC-02'                          => '-02:00',
        'UTC-11'                          => '-11:00',
        'UTC+12'                          => '+12:00',
        'Venezuela Standard Time'         => '-04:30',
        'W. Central Africa Standard Time' => '+01:00',
        'W. Europe Standard Time'         => '+01:00',
        'West Asia Standard Time'         => '+05:00',
        'West Pacific Standard Time'      => '+10:00',
    ];

    /**
     * Add other timezone map to specific PHP timezone
     *
     * @param string $otherTz
     * @param string $phpTz
     * @throws InvalidArgumentException
     * @static
     */
    public static function addOtherTzMapToPhpTz( $otherTz, $phpTz ) {
        DateTimeZoneFactory::assertDateTimeZone( $phpTz );
        self::$otherTzToPhpTz[$otherTz] = $phpTz;
    }

    /**
     * @var array  7 MS timezones to PHP timezones
     * @static
     */
    public static $otherTzToPhpTz = [
        'Afghanistan Standard Time'       => 'Asia/Kabul',
        'Fiji Standard Time'              => 'Pacific/Fiji',
        // also in 'UTC+12', below
        'Myanmar Standard Time'           => 'Asia/Yangon',
        'New Zealand Standard Time'       => 'Pacific/Auckland',
        'UTC-02'                          => 'America/Noronha',
        // also America/Godthab          - Greenland
        //      America/Miquelon         - Saint Pierre and Miquelon
        //      Atlantic/South_Georgia   - South Georgia and the South Sandwich Islands
        'UTC-11'                          => 'Pacific/Pago_Pago',
        // also Pacific/Niue, Pacific/Midway
        'UTC+12'                          => 'Pacific/Auckland',
        // also Antarctica/McMurdo, Asia/Anadyr, Asia/Kamchatka,
        //      Pacific/Fiji,  Pacific/Funafuti, Pacific/Kwajalein, Pacific/Majuro,
        //      Pacific/Nauru, Pacific/Tarawa,   Pacific/Wake,      Pacific/Wallis
    ];

    /**
     * @var array
     * @access private
     */
    private $inputiCal = [];

    /**
     * @var string
     * @access private
     */
    private $outputiCal = null;

    /**
     * @var array
     * @access private
     */
    private $vtimezoneRows = [];

    /**
     * @var array
     * @access private
     */
    private $otherTzPhpRelations = [];

    /**
     * Class constructor
     *
     * @param string|array $inputiCal    strict rfc2445 formatted calendar
     * @param array        $otherTzPhpRelations  [ other => phpTz ]
     * @throws InvalidArgumentException
     */
    public function __construct( $inputiCal = null, array $otherTzPhpRelations = [] ) {
        if( ! empty( $inputiCal )) {
            $this->setInputiCal( $inputiCal );
        }
        $this->addOtherTzPhpRelations( self::$otherTzToPhpTz );
        foreach( $otherTzPhpRelations as $otherTz => $phpTz ) {
            $this->addOtherTzPhpRelation( $otherTz, $phpTz );
        }
    }

    /**
     * Class factory method
     *
     * @param string|array $inputiCal    strict rfc2445 formatted calendar
     * @param array        $otherTzPhpRelations  [ other => phpTz ]
     * @return static
     * @throws InvalidArgumentException
     * @access static
     */
    public static function factory( $inputiCal = null, array $otherTzPhpRelations = [] ) {
        return new self( $inputiCal, $otherTzPhpRelations );
    }


    /**
     * Short static all-in-one method
     *
     * @param string|array $inputiCal    strict rfc2445 formatted calendar
     * @return string
     * @throws Exception
     * @throws InvalidArgumentException
     * @static
     */
    public static function process( $inputiCal ) {
        return self::factory( $inputiCal )
                   ->processCalendar()
                   ->getOutputiCal();
    }


    /**
     * @param string|array $inputiCal    strict rfc2445 formatted calendar
     * @return static
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function processCalendar( $inputiCal = null ) {
        if( ! empty( $inputiCal )) {
            $this->setInputiCal( $inputiCal );
        }
        $vtSwitch = false;
        foreach( $this->getInputiCal() as $lix => $row ) {
            if( StringFactory::startWith( $row, self::$BEGINVTIMEZONE )) {
                $this->setVtimezoneRow( $row );
                $vtSwitch = true;
                continue;
            }
            if( StringFactory::startWith( $row, self::$ENDVTIMEZONE )) {
                $this->setVtimezoneRow( $row );
                $this->processVtimezone();
                $this->setVtimezoneRows( [] );
                $vtSwitch = false;
                continue;
            }
            if( $vtSwitch ) {
                $this->setVtimezoneRow( $row );
                continue;
            }
            if( StringFactory::startWith( $row, self::$BEGIN ) ||
                StringFactory::startWith( $row, self::$END )) {
                $this->setOutputiCalRow( $row );
                continue;
            }
            /* split property name  and  opt.params and value */
            list( $propName, $row2 ) = StringFactory::getPropName( $row );
            if( ! Util::isPropInList( $propName, self::$TZIDPROPS )) {
                $this->setOutputiCalRow( $row );
                continue;
            }
            /* Now we have only properties with propAttr TZID */
            /* separate attributes from value */
            list( $value, $propAttr ) = self::splitContent( $row2 );
            if( ! isset( $propAttr[Vcalendar::TZID] )) {
                $this->setOutputiCalRow( $row );
                continue;
            }
            $this->processDtProp( $propName, $value, $propAttr );
        } // end foreach
        return $this;
    }

    /**
     * Process VTIMEZONE properties
     *
     * NO UTC here !! ??
     * @access private
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @access private
     * @static
     */
    private function processVtimezone() {
        static $DQ     = '"';
        $currTzId      = null;                 // empty if Vtimezone TZID is found else troublesome one
        $currTzIdFound = false;                // true if PHP Vtimezone TZID found
        $stdSwitch     = $dlghtSwitch = false; // process STANDARD/DAYLIGHT or not
        $stdArr        = $dlghtArr = [];       // TZOFFSETTO values (in  STANDARD/DAYLIGHT)
        foreach( $this->getVtimezoneRows() as $lix => $row ) {
            switch( true ) {
                case ( StringFactory::startWith( $row, self::$BEGINVTIMEZONE )) :
                    $this->setOutputiCalRow( $row );
                    continue 2;
                    break;
                case ( StringFactory::startWith( $row, self::$ENDVTIMEZONE )) :
                    if( ! empty( $currTzId )) {
                        $this->processCurrTzId( $currTzId, $stdArr, $dlghtArr );
                    }
                    $this->setOutputiCalRow( $row );
                    $currTzId      = null;
                    $currTzIdFound = false;
                    $stdSwitch     = $dlghtSwitch = false; // process STANDARD/DAYLIGHT or not
                    $stdArr        = $dlghtArr = [];       // TZOFFSETTO values (in STANDARD/DAYLIGHT)
                    continue 2;
                    break;
                case ( StringFactory::startWith( $row, self::$BEGINSTANDARD )) :
                    $this->setOutputiCalRow( $row );
                    $stdSwitch = true;
                    continue 2;
                    break;
                case ( StringFactory::startWith( $row, self::$ENDSTANDARD )) :
                    $this->setOutputiCalRow( $row );
                    $stdSwitch = false;
                    continue 2;
                    break;
                case ( StringFactory::startWith( $row, self::$BEGINDAYLIGHT )) :
                    $this->setOutputiCalRow( $row );
                    $dlghtSwitch = true;
                    continue 2;
                    break;
                case ( StringFactory::startWith( $row, self::$ENDDAYLIGHT )) :
                    $this->setOutputiCalRow( $row );
                    $dlghtSwitch = false;
                    continue 2;
                    break;
                case $currTzIdFound : // Vtimezone TZID is found, write whatever row it is
                    $this->setOutputiCalRow( $row );
                    continue 2;
                    break;
                default :
                    break; // now we go on with property rows
            }
            /* split property name  and  opt.params and value */
            list( $propName, $row2 ) = StringFactory::getPropName( $row );
            if( Vcalendar::TZOFFSETTO == $propName ) { // save offset if...
                if( $stdSwitch ) {
                    $stdArr[] = StringFactory::after_last( Util::$COLON, $row2 );
                }
                elseif( $dlghtSwitch ) {
                    $dlghtArr[] = StringFactory::after_last( Util::$COLON, $row2 );
                }
            }
            if( Vcalendar::TZID != $propName ) {  // skip all but Vtimezone TZID
                $this->setOutputiCalRow( $row );
                continue;
            }
            /* separate attributes from value */
            list( $value, $propAttr ) = StringFactory::splitContent( $row2 );
            $currTzId = $value;
            if( false !== strpos( $value, $DQ )) {
                $value = trim( $value, $DQ );
            }
            $valueNew = null;
            switch( true ) {
                case ( $this->hasOtherTzPHPtzMap( $value )) :
                    $valueNew = $this->getOtherTzPhpRelations( $value );
                    break;
                case ( isset( self::$MStimezoneToOffset[$value] )) :
                    $msTzOffset = self::$MStimezoneToOffset[$value];
                    if( empty( $msTzOffset )) {
                        $valueNew = Vcalendar::UTC;
                    }
                    else {
                        $valueNew = self::getTimeZoneNameFromOffset( $msTzOffset, false );
                    } // $valueNew is null on notFound
                    break;
                default :
                    try {
                        DateTimeZoneFactory::assertDateTimeZone( $value );
                        $currTzId      = null;
                        $currTzIdFound = true;  // NO process of STANDARD/DAYLIGHT offset
                    }
                    catch( InvalidArgumentException $e ) {
                        $valueNew = null;       // DO process of STANDARD/DAYLIGHT offset
                    }
                    break;
            } // end switch
            if( ! empty( $valueNew )) {
                $this->addOtherTzPhpRelation( $currTzId, $valueNew, false );
                $this->setOutputiCalRowElements( $propName, $valueNew, $propAttr );
                $currTzId      = null;
                $currTzIdFound = true;           // NO process of STANDARD/DAYLIGHT
            }
            else {
                $this->setOutputiCalRow( $row ); // DO process of STANDARD/DAYLIGHT
            }
        } // end foreach
    }

    /**
     * Find currTzId replacement using stdArr+dlghtArr offsets
     *
     * @param string $currTzId
     * @param array  $stdArr
     * @param array  $dlghtArr
     * @access private
     * @throws RuntimeException
     * @access private
     */
    private function processCurrTzId( $currTzId, array $stdArr, array $dlghtArr ) {
        static $ERR = 'MS timezone \'%s\' (offset std %s, dlght %s) don\'t match any PHP timezone';
        $stdTzs = $dlghtTzs = [];
        foreach( $stdArr as $offset ) {
            $stdTzs = self::getTimezoneListFromOffset( $offset, 0 ); // standard
        }
        foreach( $dlghtArr as $offset ) {
            $dlghtTzs = self::getTimezoneListFromOffset( $offset, 1 ); // daylight
        }
        foreach( $dlghtTzs as $tz => $cnt ) {
            if( isset( $stdTzs[$tz] )) {
                $dlghtTzs[$tz] += $stdTzs[$tz];
            }
        }
        foreach( $stdTzs as $tz => $cnt ) {
            if( ! isset( $dlghtTzs[$tz] )) {
                $dlghtTzs[$tz] = $cnt;
            }
        }
        if( empty( $dlghtTzs )) {
            throw new RuntimeException(
                sprintf( $ERR, $currTzId, implode( Util::$COMMA, $stdArr ), implode( Util::$COMMA, $dlghtArr ))
            );
        }
        arsort( $dlghtTzs ); // reverse sort on number of hits
        reset( $dlghtTzs );
        $tzidNew = key( $dlghtTzs );
        $this->replaceTzidInOutputiCal( $currTzId, $tzidNew );
        $this->addOtherTzPhpRelation( $currTzId, $tzidNew, false );
    }

    /**
     * Process component properties with propAttr TZID
     *
     * @param string $propName
     * @param string $value
     * @param array  $propAttr
     * @access private
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @access private
     * @todo properties RDATE, EXDATE, RRULE, EXRULE, FREEBUSY.. ??
     */
    private function processDtProp( $propName, $value, array $propAttr ) {
        $tzId = $propAttr[Vcalendar::TZID];
        switch( true ) {
            case ( $this->hasOtherTzPHPtzMap( $tzId ) ) :
                $propAttr[Vcalendar::TZID] = $this->getOtherTzPhpRelations( $tzId );
                self::checkUTC( $value, $propAttr );
                $this->setOutputiCalRowElements( $propName, $value, $propAttr );
                break;
            case ( isset( self::$MStimezoneToOffset[$tzId] ) && empty( self::$MStimezoneToOffset[$tzId] ) ) :
                $this->addOtherTzPhpRelation( $tzId, Vcalendar::UTC, false );
                $propAttr[Vcalendar::TZID] = Vcalendar::UTC;
                self::checkUTC( $value, $propAttr );
                $this->setOutputiCalRowElements( $propName, $value, $propAttr );
                break;
            default : /* check and (opt) alter timezones */
                $this->processDatePropsTZIDattribute( $propName, $value, $propAttr );
        } // end switch
    }

    /**
     * If in array, alter date-properties attribute TZID fixed. PHP-check (all) timezones, throws exception on error
     *
     * @param string $propName
     * @param string $value
     * @param array  $propAttr
     * @access private
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @access private
     */
    private function processDatePropsTZIDattribute( $propName, $value, $propAttr ) {
        $tzSwitchOk    = false;
        $tzId = $tzId2 = $propAttr[Vcalendar::TZID];
        if( isset( self::$MStimezoneToOffset[$tzId2] )) {
            try {
                $tzId = self::getTimeZoneNameFromOffset( self::$MStimezoneToOffset[$tzId2], true );
            }
            catch( RuntimeException $e ) {
                throw $e; // error exit 1
            }
            $tzSwitchOk = true;
            return;
        }
        try {
            DateTimeZoneFactory::assertDateTimeZone( $tzId );  // InvalidArgumentException
        }
        catch( InvalidArgumentException $e ) {
            throw $e; // error exit 2
        }
        if( $tzSwitchOk ) {
            $this->addOtherTzPhpRelation( $tzId2, $tzId, false );
            $propAttr[Vcalendar::TZID] = $tzId;
            self::checkUTC( $value, $propAttr );
        } // end if
        $this->setOutputiCalRowElements( $propName, $value, $propAttr );
    }

    /**
     * Return array( value, propAttr ) from property row
     *
     * @param string $row2
     * @return array   ( value, propAttr )
     * @access private
     * @static
     */
    private static function splitContent( $row2 ) {
        /* separate attributes from value */
        list( $value, $propAttr ) = StringFactory::splitContent( $row2 );
        /* fix splitContent UTC 'bug' */
        self::fixUTCx( $row2, $value, $propAttr );
        return [ $value, $propAttr ];
    }

    /**
     * Return (first found) timezone from offset, search on standard time (ie dst=0) first
     *
     * From DateTimeZoneFactory
     * @param string $offset
     * @param bool   $throwException
     * @return string   tzName
     * @throws RuntimeException    on NOT found
     * @access private
     * @static
     * @since  2.27.14 - 2019-02-26
     */
    private static function getTimeZoneNameFromOffset( $offset, $throwException = true ) {
        static $ERR = 'Offset \'%s\' (%+d seconds) don\'t match any PHP timezone';
        $seconds    = DateTimeZoneFactory::offsetToSeconds( $offset );
        $res = timezone_name_from_abbr( Util::$SP0, $seconds, 0 );
        if( false !== $res ) { // is NO dst
            return $res;
        }
        $res = timezone_name_from_abbr( Util::$SP0, $seconds );
        if( false !== $res ) { // ignores dst
            return $res;
        }
        $res = timezone_name_from_abbr( Util::$SP0, $seconds, 1 );
        if( false !== $res ) { // is dst
            return $res;
        }
        if( $throwException ) {
            throw new RuntimeException( sprintf( $ERR, $offset, $seconds ));
        }
        else {
            return null;
        }
    }

    /**
     * Returns (array) timezones that match offset ( and dst)
     *
     * @see https://www.php.net/manual/en/function.timezone-name-from-abbr.php#89155
     * @see https://www.php.net/manual/en/datetimezone.listabbreviations.php#114161
     * @param string $offset
     * @param int    $dst
     * @return array
     * @access private
     * @static
     */
    private static function getTimezoneListFromOffset( $offset, $dst ) {
        static $DST        = 'dst';
        static $OFFSET     = 'offset';
        static $TIMEZONEID = 'timezone_id';
        $seconds = DateTimeZoneFactory::offsetToSeconds( $offset );
        $output  = [];
        foreach( timezone_abbreviations_list() as $tzAbbrList ) {
            foreach( $tzAbbrList as $tzAbbrCity ) {
                if(((bool) $tzAbbrCity[$DST] !== (bool) $dst ) ||
                    empty( strlen( $tzAbbrCity[$TIMEZONEID] )) ||
                    ( $tzAbbrCity[$OFFSET] != $seconds )) {
                    continue;
                }
                $dateTimeOffsetNow = 0;
                try {
                    $date = new DateTime( null, new DateTimeZone( $tzAbbrCity[$TIMEZONEID] ));
                    $dateTimeOffsetNow = $date->getOffset();
                }
                catch( Exception $e ) {
                    $dateTimeOffsetNow = $seconds;
                    // continue; // ??
                }
                if( $seconds == $dateTimeOffsetNow ) {
                    $tzId = $tzAbbrCity[$TIMEZONEID];
                    if( isset( $output[$tzId] )) {
                        $output[$tzId] += 1;
                    }
                    else {
                        $output[$tzId] = 1;
                    }
                } // end if
            } // end foreach
        } // end foreach
        return $output;
    }

    /**
     * Suffix value with 'Z'and  remove propAttr TZID IF propAttr TZID = UTC
     *
     * @param string $value
     * @param array  $propAttr
     * @access private
     * @static
     */
    private static function checkUTC( & $value, & $propAttr ) {
        if( DateTimeZoneFactory::isUTCtimeZone( $propAttr[Vcalendar::TZID] )) {
            unset( $propAttr[Vcalendar::TZID] );
            $value .= Vcalendar::Z;
        }
    }

    /**
     * Fix StringFactory::splitContent UTC* bug
     *
     * @param string $row2
     * @param string $value
     * @param array  $propAttr
     * @access private
     * @static
     */
    private static function fixUTCx( $row2, & $value, & $propAttr ) {
        static $SCLN = ';';
        static $CLN  = ':';
        foreach( self::$UTZx as $theUTC ) {
            if( false === strpos( $row2, $theUTC )) {
                continue;
            }
            if( false !== strpos( $propAttr[Vcalendar::TZID], $SCLN )) {
                $propAttr[Vcalendar::TZID] = StringFactory::before( $SCLN, $propAttr[Vcalendar::TZID] );
            }
            if( false !== strpos( $propAttr[Vcalendar::TZID], $CLN )) {
                $propAttr[Vcalendar::TZID] = StringFactory::before( $CLN, $propAttr[Vcalendar::TZID] );
            }
            if( false !== strpos( $value, $CLN )) {
                $value = StringFactory::after_last( $CLN, $row2 );
            }
            break;
        }
    }

    /** ***********************************************************************
     *  Getters and setters etc
     */

    /**
     * @return array
     */
    public function getInputiCal() {
        return $this->inputiCal;
    }

    /**
     * @param string|array $inputiCal
     * @return static
     */
    public function setInputiCal( $inputiCal ) {
        /* get rows to parse */
        $rows = self::conformParseInput( $inputiCal );
        /* concatenate property values spread over several rows */
        $this->inputiCal = StringFactory::concatRows( $rows );
        /* Initiate output */
        $this->setVtimezoneRows( [] );
        return $this;
    }


    /**
     * @return array
     */
    public function getVtimezoneRows() {
        return $this->vtimezoneRows;
    }

    /**
     * @param string $vtimezoneRow
     * @return static
     */
    public function setVtimezoneRow( $vtimezoneRow ) {
        $this->vtimezoneRows[] = $vtimezoneRow;
        return $this;
    }

    /**
     * @param array $vtimezoneRows
     * @return static
     */
    public function setVtimezoneRows( array $vtimezoneRows = [] ) {
        $this->vtimezoneRows = $vtimezoneRows;
        return $this;
    }


    /**
     * @return string
     */
    public function getOutputiCal() {
        return $this->outputiCal;
    }

    /**
     * Replace tz in outputiCal
     *
     * @param string $tzidOld
     * @param string $tzidNew
     * @return static
     */
    public function replaceTzidInOutputiCal( $tzidOld, $tzidNew ) {
        $this->outputiCal = str_replace( $tzidOld, $tzidNew, $this->outputiCal );
        return $this;
    }

    /**
     * Append outputiCal from row
     *
     * @param string $row
     * @return static
     */
    public function setOutputiCalRow( $row ) {
        $this->outputiCal .= StringFactory::size75( $row );
        return $this;
    }

    /**
     * Append outputiCal row, built from propName, value, propAttr
     *
     * @param string $propName
     * @param string $value
     * @param array  $propAttr
     * @return static
     */
    public function setOutputiCalRowElements( $propName, $value, $propAttr ) {
        $this->outputiCal .= StringFactory::createElement(
            $propName,
            ParameterFactory::createParams( $propAttr ),
            $value
        );
        return $this;
    }


    /**
     * @param string $otherTz
     * @return string|bool|array    bool false on key not found
     */
    public function getOtherTzPhpRelations( $otherTz = null ) {
        if( ! empty( $otherTz )) {
            return $this->hasOtherTzPHPtzMap( $otherTz ) ? $this->otherTzPhpRelations[$otherTz] : false;
        }
        return $this->otherTzPhpRelations;
    }

    /**
     * @param string $otherTzKey
     * @return bool
     */
    public function hasOtherTzPHPtzMap( $otherTzKey ) {
        return ( isset( $this->otherTzPhpRelations[$otherTzKey] ));
    }

    /**
     * @param string $otherTzKey
     * @param string $phpTz
     * @param bool   $doTzAssert
     * @throws InvalidArgumentException
     * @return static
     */
    public function addOtherTzPhpRelation( $otherTzKey, $phpTz, $doTzAssert = true ) {
        if( $doTzAssert ) {
            DateTimeZoneFactory::assertDateTimeZone( $phpTz );
        }
        $this->otherTzPhpRelations[$otherTzKey] = $phpTz;
        return $this;
    }

    /**
     * @param array $otherTzPhpRelations
     * @return static
     */
    public function addOtherTzPhpRelations( array $otherTzPhpRelations ) {
        $this->otherTzPhpRelations = $otherTzPhpRelations;
        return $this;
    }


    /**
     * @var array  iCal component non-UTC date-property collection
     * @access private
     * @static
     */
    private static $TZIDPROPS  = [
        Vcalendar::DTSTART,
        Vcalendar::DTEND,
        Vcalendar::DUE,
        Vcalendar::RECURRENCE_ID,
    ];

    /**
     * @var array
     */
    private static $UTZx = [ 'UTC-02', 'UTC-11', 'UTC+12' ];

    /**
     * @var string
     * @access private
     * @static
     */
    private static $BEGIN          = 'BEGIN';
    private static $BEGINVTIMEZONE = 'BEGIN:VTIMEZONE';
    private static $BEGINSTANDARD  = 'BEGIN:STANDARD';
    private static $BEGINDAYLIGHT  = 'BEGIN:DAYLIGHT';
    private static $END            = 'END';
    private static $ENDVTIMEZONE   = 'END:VTIMEZONE';
    private static $ENDSTANDARD    = 'END:STANDARD';
    private static $ENDDAYLIGHT    = 'END:DAYLIGHT';

    /**
     * @var string
     * @access private
     * @static
     */
    private static $BEGIN_VCALENDAR = 'BEGIN:VCALENDAR';
    private static $END_VCALENDAR   = 'END:VCALENDAR';
    private static $NLCHARS         = '\n';

    /**
     * Return rows to parse from string or array
     *
     * Used by Vcalendar & RegulateTimezoneFactory
     * @param string|array $unParsedText strict rfc2445 formatted, single property string or array of strings
     * @return array
     * @throws UnexpectedValueException
     * @static
     * @since  2.29.3 - 2019-08-29
     */
    public static function conformParseInput( $unParsedText = null ) {
        static $ERR10 = 'Only %d rows in ical content :%s';
        $arrParse = false;
        if( is_array( $unParsedText )) {
            $rows     = implode( self::$NLCHARS . Util::$CRLF, $unParsedText );
            $arrParse = true;
        }
        else { // string
            $rows = $unParsedText;
        }
        /* fix line folding */
        $rows = StringFactory::convEolChar( $rows );
        if( $arrParse ) {
            foreach( $rows as $lix => $row ) {
                $rows[$lix] = StringFactory::trimTrailNL( $row );
            }
        }
        /* skip leading (empty/invalid) lines (and remove leading BOM chars etc) */
        $rows = self::trimLeadingRows( $rows );
        $cnt = count( $rows );
        if( 3 > $cnt ) { /* err 10 */
            throw new UnexpectedValueException( sprintf( $ERR10, $cnt, PHP_EOL . implode( PHP_EOL, $rows )));
        }
        /* skip trailing empty lines and ensure an end row */
        $rows = self::trimTrailingRows( $rows );
        return $rows;
    }

    /**
     * Return array to parse with leading (empty/invalid) lines removed (incl leading BOM chars etc)
     *
     * @param array $rows
     * @return array
     * @static
     * @since  2.29.3 - 2019-08-29
     */
    private static function trimLeadingRows( $rows ) {
        foreach( $rows as $lix => $row ) {
            if( false !== stripos( $row, self::$BEGIN_VCALENDAR )) {
                $rows[$lix] = self::$BEGIN_VCALENDAR;
                break;
            }
            unset( $rows[$lix] );
        }
        return $rows;
    }

    /**
     * Return array to parse with trailing empty lines removed and ensured an end row
     *
     * @param array $rows
     * @return array
     * @static
     * @since  2.29.3 - 2019-08-29
     */
    private static function trimTrailingRows( $rows ) {
        $lix = array_keys( $rows );
        $lix = end( $lix );
        while( 3 < $lix ) {
            $tst = trim( $rows[$lix] );
            if(( self::$NLCHARS == $tst ) || empty( $tst )) {
                unset( $rows[$lix] );
                $lix--;
                continue;
            }
            if( false === stripos( $rows[$lix], self::$END_VCALENDAR )) {
                $rows[] = self::$END_VCALENDAR;
            }
            else {
                $rows[$lix] = self::$END_VCALENDAR;
            }
            break;
        } // end while
        return $rows;
    }

}
