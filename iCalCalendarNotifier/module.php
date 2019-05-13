<?php
declare(strict_types=1);

include_once __DIR__ . '/../libs/base.php';
include_once __DIR__ . '/../libs/includes.php';


define( 'ICCN_Debug', true);


define( 'ICCN_RegVar_Presence', 'StatusPresence' );

define( 'ICCN_Property_PreNotifyMinutes', 'PreNotifyMinutes' );
define( 'ICCN_Property_PostNotifyMinutes', 'PostNotifyMinutes' );


class iCalCalendarNotifier extends ErgoIPSModule {

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
        react on subscribed IPS messages
    */
    public function MessageSink( $TimeStamp, $SenderID, $Message, $Data )
    {
        switch ( $Message )
        {
            case 11101: // FM_CONNECT
                // connection changed, so send update to parent
                $this->ClientChanged();
                break;
            case 11102: // FM_DISCONNECT
                // connection changed, so send update to old parent
                $this->ClientDisconnected();
                break;
        }
    }

    /*
        react on user configuration dialog
    */
    public function ApplyChanges() {
        parent::ApplyChanges();

        // filter on messages only for this address
        $this->SetReceiveDataFilter('.*' . $this->InstanceID . '.*');

        // notify parent for new configuration
        if ( IPS_GetKernelRunlevel() === KR_READY )
        {
            $this->ClientChanged();
        }
        $this->SetOldParentID(IPS_GetInstance( $this->InstanceID )['ConnectionID'] );
    }


    /***********************************************************************

    * access methods to persistence

    ************************************************************************/

    private function GetPresence(): bool
    {
        return GetValueBoolean( $this->GetIDForIdent( ICCN_RegVar_Presence ) );
    }
    private function SetPresence( $Value ): void
    {
        SetValue( $this->GetIDForIdent( ICCN_RegVar_Presence ), $Value );
    }

    // buffer persistence (does not lasts across restarts)
    private function GetPresenceReason(): string
    {
        return $this->GetBuffer('PresenceReason');
    }
    private function GetOldParentID(): int
    {
        return (int) $this->GetBuffer('OldParentID');
    }
    private function SetPresenceReason( $Value ): void
    {
        $this->SetBuffer('PresenceReason', $Value );
    }
    private function SetOldParentID( $Value ): void
    {
        $this->SetBuffer('OldParentID', $Value );
    }

    /*
        set variable and runtime status on notifications from parent
    */
    private function UpdatePresenceAndReason( $Value ): void
    {
        $NewReason = json_encode($Value['Reason'] );
        $OldReason = $this->GetPresenceReason();
        $NewPresence = $Value['Status'];
        $OldPresence = $this->GetPresence();

        if ( $NewReason !== $OldReason )
        {
            // update reason
            $this->SetPresenceReason( $NewReason );
            // set new presence, even if same value
            $this->SetPresence( $NewPresence );
        }
        if ( $NewPresence !== $OldPresence )
        {
            // this can happen e.g. by a messed up variable
            $this->SetPresence( $NewPresence );
        }
    }


    /***********************************************************************

    * update methods for parent instances

    ************************************************************************/

    /*
        a parent loses one notification instance
        -> inform parent to update its children configuration
    */
    private function ClientDisconnected(): void
    {
        $OldParentID = $this->GetOldParentID();
        if ( 0 < $OldParentID ) {
            ICCR_UpdateClientConfig($OldParentID);
        }
        // no parent connection - reset our state
        $this->SetPresence( false );
        $this->SetPresenceReason('');
    }

    /*
        a parent receives an additional one notification instance or an
        existing notification instance changed its configuration
        -> inform parent to update its children configuration
    */
    private function ClientChanged(): void
    {
        // config or connection to parent has changed, so trigger update
        $ParentID = IPS_GetInstance( $this->InstanceID )['ConnectionID'];
        if ( 0 < $ParentID )
        {
            $this->SetOldParentID( $ParentID );
            ICCR_UpdateClientConfig( $ParentID );
        }

    }


    /***********************************************************************
     * data flow from the calendar reader
     ***********************************************************************
     *
     * @param $JSONString
     */

    /*
        receiving with internal protocol from parent calendar reader instance
    */
    public function ReceiveData( $JSONString )
    {
        $Data = json_decode( $JSONString, true );
        if (( ICCR_TX === $Data['DataID'] ) && ($this->InstanceID === $Data['InstanceID'] ) )
        {
            $this->UpdatePresenceAndReason($Data['Notify'] );
        }
    }


    /***********************************************************************

    * methods for script access

    ************************************************************************/

    public function GetNotifierPresence(): bool
    {
        return $this->GetPresence();
    }

    public function GetNotifierPresenceReason(): string
    {
        return $this->GetPresenceReason();
    }

}


