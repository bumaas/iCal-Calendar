<?php

if (!defined('_ErgomationBase')) {
    define('_ErgomationBase', 1);

    class ErgoIPSModule extends IPSModule
    {

        protected function IsDebug(): bool
        {
            return false;
        }

        protected function GetLogID(): string
        {
            return get_class($this);
        }

        protected function LogError($Error): void
        {
            IPS_LogMessage($this->GetLogID(), $Error);
        }

        protected function LogDebug($Debug): void
        {
            if ($this->IsDebug()) {
                IPS_LogMessage($this->GetLogID() . ' Debug', $Debug);
            }
        }

    }
}


