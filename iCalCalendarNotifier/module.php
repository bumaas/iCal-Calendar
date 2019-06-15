<?php
declare(strict_types=1);

include_once __DIR__ . '/../libs/includes.php';


define( 'ICCN_Debug', true);


define( 'ICCN_RegVar_Presence', 'StatusPresence' );

define( 'ICCN_Property_PreNotifyMinutes', 'PreNotifyMinutes' );
define( 'ICCN_Property_PostNotifyMinutes', 'PostNotifyMinutes' );


class iCalCalendarNotifier extends IPSModule {

    /***********************************************************************
     * customized debug methods
     ***********************************************************************
     *
     * @return bool
     */

    /*
        debug on/off is a defined constant
    */
    protected function IsDebug():bool
    {
        return ICCN_Debug;
    }

    /*
        sender for debug messages is set
    */
    protected function GetLogID(): string
    {
        return IPS_GetName( $this->InstanceID );
    }


    /***********************************************************************

    * standard module methods

    ************************************************************************/

    /*
        basic setup
    */
    public function Create()
    {
        parent::Create();

        // create status variable
        $this->RegisterVariableBoolean( ICCN_RegVar_Presence, 'Presence', '~Presence', $this->InstanceID );

        // create configuration properties
        $this->RegisterPropertyInteger( ICCN_Property_PreNotifyMinutes, 72 * 60 );
        $this->RegisterPropertyInteger( ICCN_Property_PostNotifyMinutes, 36 * 60);

        // initialize persistence
        $this->SetBuffer('PresenceReason', '');
        $this->SetBuffer('OldParentID', '');

        // subscribe to IPS messages
        $this->RegisterMessage( $this->InstanceID, FM_CONNECT );
        $this->RegisterMessage( $this->InstanceID, FM_DISCONNECT );

        // connect to existing iCal Calendar Reader, or create new instance
        $this->ConnectParent(ICCR_INSTANCE_GUID );
    }

    /*
        react on user configuration dialog
    */
    public function ApplyChanges() {
        parent::ApplyChanges();

        $this->SetStatus(IS_INACTIVE);
    }


}


