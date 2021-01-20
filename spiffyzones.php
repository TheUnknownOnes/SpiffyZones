#!/usr/bin/php
<?php

namespace TUO\SpiffyZones;

use Exception;
use Generator;
use stdClass;
use Throwable;

class XEventReader
{
    protected $Process;
    protected array $Pipes = [];

    public function __construct()
    {
        $Descriptorspec = array(
            1 => ['pipe', 'w']
        );
        $this->Process = proc_open(['xev', '-root'], $Descriptorspec, $this->Pipes);

        if (! is_resource($this->Process)) {
            throw new Exception('Failed to run "xev"!');
        }
    }

    /**
     *
     * @return Generator<XEvent>
     */
    public function run() : Generator
    {
        $ReadStreams = [$this->Pipes[1]];
        $WriteStream = null;
        $ExceptStreams = null;
        $NumStreams = @stream_select($ReadStreams, $WriteStream, $ExceptStreams, 0, 100000);
        $Eventstring = '';

        if (($NumStreams !== false) && ($NumStreams > 0)) {
            do {
                $Line = fgets($this->Pipes[1]);
                if ($Line !== PHP_EOL) {
                    $Line = preg_replace('/(\s)\s+/', '$1', rtrim($Line));
                    if (($Eventstring !== '') && (strpos($Line, ' ') !== 0)) {
                        $Line = ' ' . $Line;
                    }
                    $Eventstring .= $Line;
                } else {
                    $NewEvent = XEvent::create($Eventstring);
                    if (! is_null($NewEvent)) {
                        yield $NewEvent;
                    }
                    $Eventstring = '';
                }
                $NumStreams = stream_select($ReadStreams, $WriteStream, $ExceptStreams, 0, 50000);
            } while (($NumStreams !== false) && ($NumStreams > 0));
        }
    }
}

class XEvent
{
    public string $_Name = '';

    /**
     * @param string $Eventstring
     * @return XEvent|null
     */
    public static function create(string $Eventstring)
    {
        $EventData = explode(', ', $Eventstring);
        $Matches = [];
        if (preg_match('/^(.+) event/', array_shift($EventData), $Matches) == 1) {
            return new XEvent($Matches[1], $EventData);
        } else {
            return null;
        }
    }

    protected function parseParams(array $Params)
    {
        foreach ($Params as $Index => $Param) {
            $Matches = [];
            if (preg_match('/^(\w+)[:\s]+(.+)$/', $Param, $Matches) == 1) {
                $ParamName = $Matches[1];
                $ParamValue = $Matches[2];
                
                if (preg_match('/^(\d+)$/', $ParamValue, $Matches) == 1) {
                    $this->{$ParamName} = intval($ParamValue);
                } elseif (preg_match('/^\d+(\s)(?:\d|\1)+$/', $ParamValue, $Matches) == 1) {
                    foreach (explode($Matches[1], $ParamValue) as $Number) {
                        $this->{$ParamName}[] = intval($Number);
                    }
                } elseif (preg_match('/^0x([0-9a-f]+)$/i', $ParamValue, $Matches) == 1) {
                    $this->{$ParamName} = hexdec($ParamValue);
                } elseif (preg_match('/^\((\d+),(\d+)\)$/', $ParamValue, $Matches) == 1) {
                    $this->{$ParamName} = ['x' => intval($Matches[1]), 'y' => intval($Matches[2])];
                } elseif (preg_match('/^(YES|NO)$/', $ParamValue, $Matches) == 1) {
                    $this->{$ParamName} = strtolower($Matches[1]) == 'yes';
                } else {
                    $this->{$ParamName} = $ParamValue;
                }
            } elseif (preg_match('/^\((-?\d+),(-?\d+)\)$/', $Param, $Matches) == 1) {
                $this->{"Param{$Index}"} = ['x' => intval($Matches[1]), 'y' => intval($Matches[2])];
            } else {
                $this->{"Param{$Index}"} = $Param;
            }
        }
    }

    public function __construct(string $Name, array $Params)
    {
        $this->_Name = $Name;
        $this->parseParams($Params);
    }
}

class TKEvent
{
    private const PARAM_DELIMITER = '|';
    private const PARAM_VALUE_DELIMITER = '=';
    public string $_WidgetID;
    public string $_Name;

    public function __construct(string $EventData)
    {
        $Matches = [];
        if (preg_match('/^Event ([\w\.]+) (\w+) ?(.+)?$/', $EventData, $Matches) == 1) {
            $this->_WidgetID = $Matches[1];
            $this->_Name = $Matches[2];
            if (array_key_exists(3, $Matches)) {
                foreach (explode(self::PARAM_DELIMITER, $Matches[3]) as $Param) {
                    $ParamParts = explode(self::PARAM_VALUE_DELIMITER, $Param);
                    if (count($ParamParts) == 2) {
                        $this->{$ParamParts[0]} = $ParamParts[1];
                    }
                }
            }
        }
    }

    public static function generateEventString(string $WidgetID, string $EventName, array $EventParams = []) : string
    {
        $Result = sprintf('Event %s %s', $WidgetID, $EventName);
        $FirstParam = true;

        foreach ($EventParams as $ParamName => $ParamValue) {
            $Result .= sprintf('%s%s%s%s', $FirstParam ? ' ' : self::PARAM_DELIMITER, $ParamName, self::PARAM_VALUE_DELIMITER, strval($ParamValue));
            $FirstParam = false;
        }
        $Result = str_replace(['"', PHP_EOL], ['', '\n'], $Result);
        return $Result;
    }
}

class TCLShell
{
    protected $Process;
    protected array $Pipes = [];
    protected bool $Debug;
    /** @var array<TKWindow> */
    protected array $Windows = [];

    protected function getProcessIsActive() : bool
    {
        if (is_resource($this->Process)) {
            $Info = proc_get_status($this->Process);
            return $Info['running'];
        } else {
            return false;
        }
    }

    protected function debug(string $Context, string $Message)
    {
        if ($this->Debug) {
            echo sprintf('[%s] %s', $Context, $Message) . PHP_EOL;
        }
    }

    public function __construct(bool $Debug = false)
    {
        $this->Debug = $Debug;

        $Descriptorspec = array(
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w']
        );
        $this->Process = proc_open(['tclsh'], $Descriptorspec, $this->Pipes);
        if (! is_resource($this->Process)) {
            throw new Exception('Failed to run "tclsh"!');
        } else {
            $this->execute('package require Tk');
            $this->execute('wm withdraw .');
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function execute(string $Command) : bool
    {
        if ($this->getProcessIsActive()) {
            $this->debug(__METHOD__, $Command);
            fwrite($this->Pipes[0], $Command . chr(13));
            return true;
        } else {
            return false;
        }
    }

    public function read(string $Command) : string
    {
        if ($this->execute("puts [{$Command}]")) {
            $ReadStreams = [$this->Pipes[1]];
            $WriteStream = null;
            $ExceptStreams = null;
            $Result = '';
            $NumStreams = stream_select($ReadStreams, $WriteStream, $ExceptStreams, null);
            if (($NumStreams !== false) && ($NumStreams > 0)) {
                do {
                    $Result .= fgets($this->Pipes[1]);
                    $NumStreams = stream_select($ReadStreams, $WriteStream, $ExceptStreams, 0, 50000);
                } while (($NumStreams !== false) && ($NumStreams > 0));
            }
            return $Result;
        } else {
            return '';
        }
    }

 
    public function run()
    {
        if ($this->getProcessIsActive()) {
            $ReadStreams = [$this->Pipes[1]];
            $WriteStream = null;
            $ExceptStreams = null;
            $Result = '';
            $NumStreams = stream_select($ReadStreams, $WriteStream, $ExceptStreams, 100000);
            if (($NumStreams !== false) && ($NumStreams > 0)) {
                do {
                    $Result .= fgets($this->Pipes[1]);
                    $NumStreams = stream_select($ReadStreams, $WriteStream, $ExceptStreams, 0, 50000);
                } while (($NumStreams !== false) && ($NumStreams > 0));

                foreach (explode(PHP_EOL, $Result) as $Line) {
                    if (strpos($Line, 'Event') === 0) {
                        $Event = new TKEvent($Line);
                        if (! is_null($Event)) {
                            foreach ($this->Windows as $Window) {
                                if (strpos($Event->_WidgetID, $Window->getID()) === 0) {
                                    $Window->handleEvent($Event);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function close()
    {
        if ($this->getProcessIsActive()) {
            $this->execute('exit');
            proc_close($this->Process);
            $this->Process = null;
        }
    }

    public function addWindow(TKWindow $Window) : TCLShell
    {
        $this->Windows[$Window->getID()] = $Window;
        return $this;
    }

    public function removeWindow(TKWindow $Window) : TCLShell
    {
        if (array_key_exists($Window->getID(), $this->Windows)) {
            unset($this->Windows[$Window->getID()]);
        }
        return $this;
    }
}

class TKWidget
{
    protected TCLShell $Shell;
    protected string $ID;
    protected ?TKWidget $Parent;
    /** @var array<TKWidget> */
    protected array $Children = [];

    protected static int $WidgetIDCounter = 0;
    protected static function generateID() : string
    {
        return '.' . self::$WidgetIDCounter++;
    }
    
    public function __construct(TKWidget $Parent)
    {
        $this->Shell = $Parent->getShell();
        $this->Parent = $Parent;
        $this->ID = $Parent->getID() . self::generateID();
        $Parent->addWidget($this);
    }

    public function getID() : string
    {
        return $this->ID;
    }

    public function getShell() : TCLShell
    {
        return $this->Shell;
    }

    public function destroy()
    {
        $this->Shell->execute("destroy {$this->ID}");
        return $this;
    }

    public function configure(string $Property, string $Value) : TKWidget
    {
        $this->Shell->execute("{$this->ID} configure -{$Property} {$Value}");
        return $this;
    }

    public function setBackgroundColor(string $Color) : TKWidget
    {
        $this->configure('background', $Color);
        return $this;
    }

    public function addWidget(TKWidget $Widget) : TKWidget
    {
        $this->Children[$Widget->getID()] = $Widget;
        return $this;
    }

    public function removeWidget(TKWidget $Widget) : TKWidget
    {
        if (array_key_exists($Widget->getID(), $this->Children)) {
            unset($this->Children[$Widget->getID()]);
        }
        return $this;
    }

    public function addXEventHandler(string $XEvent, string $EventName, array $Params = [])
    {
        $this->Shell->execute(sprintf('bind %s <%s> {puts "%s"}', $this->ID, $XEvent, TKEvent::generateEventString($this->ID, $EventName, $Params)));
    }

    public function handleEvent(TKEvent $Event)
    {
        if ($Event->_WidgetID == $this->ID) {
            $MethodName = $Event->_Name;
            if (method_exists($this, $MethodName)) {
                call_user_func([$this, $MethodName], $Event);
            }
        } else {
            foreach ($this->Children as $Child) {
                if (strpos($Event->_WidgetID, $Child->getID()) === 0) {
                    $Child->handleEvent($Event);
                }
            }
        }
    }

    public function pack(array $Options = []) : TKWidget
    {
        $OptionsText = '';
        foreach ($Options as $OptionName => $OptionValue) {
            $OptionsText .= " -{$OptionName} {$OptionValue}";
        }
        $this->Shell->execute("pack {$this->ID} {$OptionsText}");
        
        return $this;
    }
}

class Rect
{
    public int $X;
    public int $Y;
    public int $Width;
    public int $Height;

    public static function createFromTKGeometry(string $Geometry)
    {
        $Matches = [];
        if (preg_match('/(\d+)x(\d+)\+(\d+)\+(\d+)/', $Geometry, $Matches) === 1) {
            return new Rect(intval($Matches[3]), intval($Matches[4]), intval($Matches[1]), intval($Matches[2]));
        } else {
            throw new Exception(sprintf('Invalid geometry data "%s"!', $Geometry));
        }
    }

    public static function createFromObject(object $Data)
    {
        if (property_exists($Data, 'X')) {
            $X = $Data->X;
        } else {
            $X = 0;
        }

        if (property_exists($Data, 'Y')) {
            $Y = $Data->Y;
        } else {
            $Y = 0;
        }

        if (property_exists($Data, 'Width')) {
            $Width = $Data->Width;
        } else {
            $Width = 0;
        }

        if (property_exists($Data, 'Height')) {
            $Height = $Data->Height;
        } else {
            $Height = 0;
        }

        return new Rect($X, $Y, $Width, $Height);
    }

    public function __construct(int $X, int $Y, int $Width, int $Height)
    {
        $this->X = $X;
        $this->Y = $Y;
        $this->Width = $Width;
        $this->Height = $Height;
    }

    public function asTKGeometry()
    {
        return "{$this->Width}x{$this->Height}+{$this->X}+{$this->Y}";
    }

    public function asWMCtrlMVArg()
    {
        return "0,{$this->X},{$this->Y},{$this->Width},{$this->Height}";
    }

    public function containsPoint(int $X, int $Y)
    {
        return ($X >= $this->X) && ($X < $this->X + $this->Width) && ($Y >= $this->Y) && ($Y < $this->Y + $this->Height);
    }
}

class TKWindow extends TKWidget
{
    public const TYPE_DESKTOP = 'desktop';
    public const TYPE_DOCK = 'dock';
    public const TYPE_TOOLBAR = 'toolbar';
    public const TYPE_MENU = 'menu';
    public const TYPE_UTILITY = 'utility';
    public const TYPE_SPLASH = 'splash';
    public const TYPE_DIALOG = 'dialog';
    public const TYPE_DROPDOWN_MENU = 'dropdown_menu';
    public const TYPE_POPUPMENU = 'popup_menu';
    public const TYPE_TOOLTIP = 'tooltip';
    public const TYPE_NOTIFICATION = 'notification';
    public const TYPE_COMBO = 'combo';
    public const TYPE_DND = 'dnd';
    public const TYPE_NORMAL = 'normal';

    public function __construct(TCLShell $Shell)
    {
        $this->Shell = $Shell;
        $this->ID = self::generateID();
        $this->Shell->execute("toplevel {$this->ID}");
        $this->addXEventHandler('Destroy', 'OnDestroy');
        $this->Shell->execute('update');
        $Shell->addWindow($this);
    }

    protected function OnDestroy($Event)
    {
        $this->Shell->removeWindow($this);
    }

    public function setCaption(string $Caption) : TKWindow
    {
        $this->Shell->execute("wm title {$this->ID} \"{$Caption}\"");
        return $this;
    }

    public function setPosition(int $Left, int $Top) : TKWindow
    {
        $this->Shell->execute("wm geometry {$this->ID} +{$Left}+{$Top}");
        return $this;
    }

    public function setSize(int $Width, int $Height) : TKWindow
    {
        $this->Shell->execute("wm geometry {$this->ID} {$Width}x{$Height}");
        return $this;
    }

    public function setGeometry(Rect $Geometry) : TKWindow
    {
        $this->Shell->execute("wm geometry {$this->ID} {$Geometry->asTKGeometry()}");
        return $this;
    }

    public function getGeometry() : Rect
    {
        return Rect::createFromTKGeometry($this->Shell->read("wm geometry {$this->ID}"));
    }

    public function setAttributes(array $Attributes) : TKWindow
    {
        if (count($Attributes) > 0) {
            $AttributesText = '';
            foreach ($Attributes as $AttributeName => $AttributeValue) {
                $AttributesText .= "-{$AttributeName} {$AttributeValue}";
            }
            $this->Shell->execute("wm attributes {$this->ID} {$AttributesText}");
        }
        
        return $this;
    }
    
    public function setAlpha(float $Alpha) : TKWindow
    {
        return $this->setAttributes(['alpha' => sprintf('%.3f', $Alpha)]);
    }

    public function setTopmost(bool $Topmost) : TKWindow
    {
        return $this->setAttributes(['topmost' => $Topmost ? 1 : 0]);
    }

    public function setType(string $Type): TKWindow
    {
        return $this->setAttributes(['type' => $Type]);
    }

    public function hide() : TKWindow
    {
        $this->Shell->execute("wm withdraw {$this->ID}");
        return $this;
    }

    public function show() : TKWindow
    {
        $this->Shell->execute("wm deiconify {$this->ID}");
        return $this;
    }
}

class TKButton extends TKWidget
{
    public function __construct(TKWidget $Parent, string $Text = 'Button')
    {
        parent::__construct($Parent);
        $this->Shell->execute(sprintf('button %s -command {puts "%s"} -text "%s"', $this->ID, TKEvent::generateEventString($this->ID, 'OnClick'), $Text));
    }

    protected function OnClick(TKEvent $Event)
    {
    }
}

class TKFrame extends TKWidget
{
    public function __construct(TKWidget $Parent)
    {
        parent::__construct($Parent);
        $this->Shell->execute("frame {$this->ID}");
    }
}

class Zone extends TKWindow
{
    protected App $App;

    public function __construct(App $App)
    {
        $this->App = $App;

        parent::__construct($App->getShell());
        $this
            ->setType(TKWindow::TYPE_UTILITY)
            ->setAlpha(0.9)
            ->setCaption(sprintf('Spiffy Zone'))
            ->setTopmost(true)
            ->setBackgroundColor('LightSkyBlue2');
    }

    protected function OnDestroy($Event)
    {
        parent::OnDestroy($Event);
        $this->App->removeZone($this);
    }
}

class ConfigurationZone extends Zone
{
    public function __construct(AppConfigure $App)
    {
        parent::__construct($App);
        $this->setSize(300, 300);
        $this->App = $App;
        
        (new AddZoneButton($this, $this->App))
            ->pack(['pady' => 10]);
        (new SaveConfigButton($this, $this->App))
            ->pack(['pady' => 10]);
    }
}

class DropZone extends Zone
{
    public ConfigZone $Config;

    public function __construct(AppDaemon $App, ConfigZone $Config)
    {
        $this->App = $App;
        $this->Config = $Config;
        parent::__construct($this->App);
        $this
            ->hide()
            ->setGeometry($Config->Rect);
    }
}

class AddZoneButton extends TKButton
{
    protected AppConfigure $App;

    public function __construct(TKWidget $Parent, AppConfigure $App)
    {
        $this->App = $App;
        parent::__construct($Parent, 'Add zone');
    }

    protected function OnClick(TKEvent $Event)
    {
        $this->App->addZone();
    }
}

class SaveConfigButton extends TKButton
{
    protected AppConfigure $App;

    public function __construct(TKWidget $Parent, AppConfigure $App)
    {
        $this->App = $App;
        parent::__construct($Parent, 'Save config');
    }

    protected function OnClick(TKEvent $Event)
    {
        $this->App->saveConfig();
    }
}

class Config
{
    public const DEFAULT_PROFILE_NAME = '__DEFAULT';
    protected string $CurrentProfileName = self::DEFAULT_PROFILE_NAME;

    public const MODIFIER_SHIFT = 1 << 0;
    public const MODIFIER_CAPSLOCK = 1 << 1;
    public const MODIFIER_CONTROL = 1 << 2;
    public const MODIFIER_ALT = 1 << 3;
    public const MODIFIER_NUMLOCK = 1 << 4;
    //public const MODIFIER_UNKNOWN1 = 1 < 5;
    public const MODIFIER_SUPER = 1 << 6;
    //public const MODIFIER_UNKNOWN2 = 1 < 7;
    public const MODIFIER_BUTTON1 = 1 << 8;
    public const MODIFIER_BUTTON2 = 1 << 9;
    public const MODIFIER_BUTTON3 = 1 << 10;
    public const MODIFIER_BUTTON4 = 1 << 11;
    public const MODIFIER_BUTTON5 = 1 << 12;
    
    /** @var array<ConfigProfile> */
    public array $Profiles = [];

    public static function createFromObject(object $Data) : Config
    {
        $Result = new Config();

        if (property_exists($Data, 'Profiles') && (is_object($Data->Profiles))) {
            foreach ($Data->Profiles as $ProfileName => $Profile) {
                if (is_object($Profile)) {
                    $Result->Profiles[$ProfileName] = ConfigProfile::createFromObject($Profile);
                }
            }
        }
        return $Result;
    }

    public function __construct()
    {
        $this->Profiles[self::DEFAULT_PROFILE_NAME] = new ConfigProfile();
    }

    public function setCurrentProfile(string $ProfileName)
    {
        if (! array_key_exists($ProfileName, $this->Profiles)) {
            $this->Profiles[$ProfileName] = new ConfigProfile($ProfileName);
        }
        $this->CurrentProfileName = $ProfileName;
    }

    public function getCurrentProfile() : ConfigProfile
    {
        if (array_key_exists($this->CurrentProfileName, $this->Profiles)) {
            return $this->Profiles[$this->CurrentProfileName];
        } else {
            return $this->Profiles[self::DEFAULT_PROFILE_NAME];
        }
    }
}

class ConfigProfile
{

    /** @var array<ConfigZone> */
    public array $Zones = [];

    public static function createFromObject(object $Data) : ConfigProfile
    {
        $Result = new ConfigProfile();

        if (property_exists($Data, 'Zones') && (is_array($Data->Zones))) {
            foreach ($Data->Zones as $Zone) {
                if (is_object($Zone)) {
                    $Result->Zones[] = ConfigZone::createFromObject($Zone);
                }
            }
        }

        return $Result;
    }
}

class ConfigZone
{
    public Rect $Rect;

    public static function createFromObject(object $Data) : ConfigZone
    {
        $Result = new ConfigZone;
        if (property_exists($Data, 'Rect') && (is_object($Data->Rect))) {
            $Result->Rect = Rect::createFromObject($Data->Rect);
        } else {
            $Result->Rect = new Rect(0, 0, 100, 100);
        }
            
        return $Result;
    }

    public static function createFromGeometry(Rect $Geometry) : ConfigZone
    {
        $Result = new ConfigZone;
        $Result->Rect = $Geometry;
        
        return $Result;
    }
}

class XWinInfo
{

    /**
     * @param int $WindowID
     * @return array<int>
     */
    public static function getChildren(int $WindowID) : array
    {
        $Lines = [];
        exec("xwininfo -id {$WindowID} -children", $Lines);

        $Result = [];
        $Matches = [];
        foreach ($Lines as $Line) {
            if (preg_match('/0x([a-f\d]+) [\("].+[\)"]: \(.*\)/i', $Line, $Matches) === 1) {
                $Result[] = hexdec($Matches[1]);
            }
        }

        return $Result;
    }
}

class WMWindow
{
    public int $ID;
    public int $Desktop;
    public int $PID;
    public Rect $Geometry;
    public string $ClientMachine;
    public string $Title;

    public function __construct(int $ID, int $Desktop, int $PID, Rect $Geometry, string $ClientMachine, string $Title)
    {
        $this->ID = $ID;
        $this->Desktop = $Desktop;
        $this->PID = $PID;
        $this->Geometry = $Geometry;
        $this->ClientMachine = $ClientMachine;
        $this->Title = $Title;
    }

    public function setGeometry(Rect $Geometry)
    {
        WMCtrl::moveWindow($this->ID, $Geometry);
    }
}

class WMDesktop
{
    public int $ID;
    public bool $IsCurrent;

    public function __construct(int $ID, bool $IsCurrent)
    {
        $this->ID = $ID;
        $this->IsCurrent = $IsCurrent;
    }
}

class WMCtrl
{
    /**
     * @return array<WMWindow>
     */
    public static function getWindows() : array
    {
        $Lines = [];
        exec("wmctrl -l -p -G", $Lines);

        $Matches = [];
        $Result = [];

        foreach ($Lines as $Line) {
            if (preg_match('/0x([a-f\d]+) +(-?\d+) +(\d+) +(-?\d+) +(-?\d+) +(\d+) +(\d+) +([^\s]+) +(.+)/i', $Line, $Matches) === 1) {
                $WindowID = hexdec($Matches[1]);
                
                $Result[$WindowID] = new WMWindow(
                    $WindowID,
                    intval($Matches[2]),
                    intval($Matches[3]),
                    new Rect(intval($Matches[4]), intval($Matches[5]), intval($Matches[6]), intval($Matches[7])),
                    $Matches[8],
                    $Matches[9]
                );
            }
        }

        return $Result;
    }

    public static function moveWindow(int $WindowID, Rect $NewGeometry)
    {
        $ManagedWindows = self::getWindows();
        if (! array_key_exists($WindowID, $ManagedWindows)) {
            $Children = XWinInfo::getChildren($WindowID);
            
            foreach ($Children as $ChildWindowID) {
                if (array_key_exists($ChildWindowID, $ManagedWindows)) {
                    $WindowID = $ChildWindowID;
                    break;
                }
            }
        }

        exec("wmctrl -i -r {$WindowID} -e {$NewGeometry->asWMCtrlMVArg()}");
    }

    /**
     * @return array<WMDesktop>
     */
    public static function getDesktops() : array
    {
        $Lines = [];
        exec("wmctrl -d", $Lines);

        $Matches = [];
        $Result = [];

        foreach ($Lines as $Line) {
            if (preg_match('/(\d+) +([*-]) DG:/', $Line, $Matches) === 1) {
                $Result[] = new WMDesktop(intval($Matches[1]), $Matches[2] == '*');
            }
        }
        return $Result;
    }

    public static function getCurrentDesktop() : WMDesktop
    {
        foreach (WMCtrl::getDesktops() as $Desktop) {
            if ($Desktop->IsCurrent) {
                return $Desktop;
            }
        }
    }
}

class Shell
{
    public static function getCommandExists($Command)
    {
        $Output = shell_exec("which {$Command} 2>/dev/null");
        return $Output != '';
    }
}


class App
{
    protected const VERSION = 1;

    protected const APPMODE_CONFIG = 0;
    protected const APPMODE_DAEMON = 1;
    protected const APPMODE_SHOW_HELP = 2;

    protected static int $AppMode = self::APPMODE_SHOW_HELP;
    protected static string $ProfileName = Config::DEFAULT_PROFILE_NAME;

    protected TCLShell $Shell;
    protected string $Configfile;
    protected Config $Config;
    protected bool $Stop = false;
    protected string $PIDFileName;


    public static function execute()
    {
        self::checkRequirements();
        try {
            self::parseArguments();
        } catch (Exception $Exception) {
            self::showHelp($Exception);
            return;
        }


        switch (self::$AppMode) {
            case self::APPMODE_DAEMON:
                (new AppDaemon())->run();
                break;
            case self::APPMODE_CONFIG:
                (new AppConfigure())->run();
                break;
            case self::APPMODE_SHOW_HELP:
            default:
                self::showHelp();
        }
    }

    protected static function showHelp(?Exception $Exception = null)
    {
        if ($Exception != null) {
            echo "Error: " . $Exception->getMessage() . PHP_EOL . PHP_EOL;
        }
        
        $Helptext = [
            "Spiffy Zones (Version " . self::VERSION . ")",
            "Usage: " . basename(__FILE__) . ' [OPTIONS]',
            "",
            "Options:",
            "  -h, --help" => "Show this help",
            "  -c, --config" => "Configuration mode",
            "  -d, --daemon" => "Run as daemon (provides the core-functionality)",
            "  -p, --profile PROFILE" => "Run in or configure profile",
            "\e" //Last line; wont be printed
        ];
        

        $TableBuffer = [];
        $TableMaxLenCol1 = 0;
        foreach ($Helptext as $Index => $Line) {
            if (is_string($Index)) {
                $TableBuffer[$Index] = $Line;
                $TableMaxLenCol1 = max($TableMaxLenCol1, strlen($Index));
            } else {
                foreach ($TableBuffer as $Col1 => $Col2) {
                    echo $Col1 . str_repeat(' ', $TableMaxLenCol1 - strlen($Col1) + 1) . $Col2 . PHP_EOL;
                }
                $TableBuffer = [];
                $TableMaxLenCol1 = 0;

                if ($Line !== "\e") {
                    echo $Line . PHP_EOL;
                }
            }
        }
    }
    
    protected static function checkRequirements()
    {
        $MissingDeps = [];

        $Commands = ['xev', 'wmctrl', 'xwininfo', 'wish'];
        foreach ($Commands as $Command) {
            if (! Shell::getCommandExists($Command)) {
                $MissingDeps[] = "Command '{$Command}'";
            }
        }

        $PHPExtensions = ['json', 'posix', 'pcntl'];
        foreach ($PHPExtensions as $Ext) {
            if (! extension_loaded($Ext)) {
                $MissingDeps[] = "PHP-Extension '{$Ext}'";
            }
        }

        if (count($MissingDeps) > 0) {
            throw new Exception(sprintf('Missing dependencies: %s', implode(', ', $MissingDeps)));
        }
    }

    protected static function parseArguments()
    {
        global $argv;
        
        for ($idx = 1; $idx < count($argv); $idx++) {
            switch (strtolower($argv[$idx])) {
                case '-d':
                case '--daemon':
                    self::$AppMode = self::APPMODE_DAEMON;
                    break;
                case '-c':
                case '--config':
                    self::$AppMode = self::APPMODE_CONFIG;
                    break;
                case '-h':
                case '--help':
                    self::$AppMode = self::APPMODE_SHOW_HELP;
                    break;
                case '-p':
                case '--profile':
                    $idx++;
                    if ($idx < count($argv)) {
                        self::$ProfileName = $argv[$idx];
                    } else {
                        throw new Exception('No profile name specified after -p|--profile!');
                    }
                    break;
            }
        }
    }

    protected static function getConfigFile() : string
    {
        $ConfDir = getenv('XDG_CONFIG_HOME');
        if (($ConfDir === false) || ($ConfDir === '') || (! is_dir($ConfDir))) {
            $ConfDir = getenv('HOME') . '/.config';
        }
        return $ConfDir . '/spiffyzones.json';
    }

    protected static function generatePIDFileName()
    {
        $ID = getenv('DISPLAY');
        $ID = $ID === false ? getenv('XDG_SESSION_ID') : $ID;
        return sys_get_temp_dir() . "/spiffyzones_{$ID}.pid";
    }

    public function getShell() : TCLShell
    {
        return $this->Shell;
    }

    public function loadConfig()
    {
        if (file_exists($this->Configfile)) {
            $this->Config = Config::createFromObject(json_decode(file_get_contents($this->Configfile)));
        } else {
            $this->Config = new Config();
        }

        $this->Config->setCurrentProfile(self::$ProfileName);
    }

    public function run()
    {
    }
    
    public function __construct()
    {
        $this->Configfile = self::getConfigFile();
        $this->loadConfig();
        $this->Shell  = new TCLShell();
        $this->PIDFileName = self::generatePIDFileName();
    }

    public function removeZone(Zone $Zone)
    {
    }
}

class AppConfigure extends App
{
    /** @var array<ConfigurationZone> */
    protected array $Zones = [];

    public function run()
    {
        if (count($this->Config->getCurrentProfile()->Zones) == 0) {
            $this->addZone();
        } else {
            foreach ($this->Config->getCurrentProfile()->Zones as $Zone) {
                $ConfigZone = new ConfigurationZone($this);
                $ConfigZone->setGeometry($Zone->Rect);
                $this->Zones[] = $ConfigZone;
            }
        }

        
        while (! $this->Stop) {
            $this->Shell->run();
        }
    }

    public function addZone()
    {
        $this->Zones[] = new ConfigurationZone($this);
    }

    public function removeZone(Zone $Zone)
    {
        $Index = array_search($Zone, $this->Zones);
        if ($Index !== false) {
            unset($this->Zones[$Index]);
        }

        $this->Stop = count($this->Zones) == 0;
    }

    public function saveConfig()
    {
        $this->Config->getCurrentProfile()->Zones = [];
        foreach ($this->Zones as $Zone) {
            $this->Config->getCurrentProfile()->Zones[] = ConfigZone::createFromGeometry($Zone->getGeometry());
        }

        file_put_contents($this->Configfile, json_encode($this->Config));
        $this->notifyDaemon();
        $this->Stop = true;
    }

    protected function notifyDaemon()
    {
        if (file_exists($this->PIDFileName)) {
            $PID = intval(file_get_contents($this->PIDFileName));
            posix_kill($PID, SIGHUP);
        }
    }
}

class AppDaemon extends App
{
    protected XEventReader $Reader;
    /** @var array<DropZone> */
    protected array $Zones = [];
    protected bool $ZonesVisible = false;

    protected bool $DragStarted = false;
    protected int $ChangingWindow = 0;
    protected int $ChangingWindowWidth = 0;
    protected int $ChangingWindowHeight = 0;
    protected bool $ChangingWindowIsMoving = false;
    protected ?DropZone $MatchingZone = null;
    protected $PIDFile;

    public function __construct()
    {
        parent::__construct();
        $this->Reader = new XEventReader();
        $this->createZones();

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'OnSignal']);
        pcntl_signal(SIGHUP, [$this, 'OnSignal']);

        $this->createPIDFile();
    }

    protected function createPIDFile()
    {
        if (file_exists($this->PIDFileName)) {
            $PID = intval(file_get_contents($this->PIDFileName));
            posix_kill($PID, SIGTERM);
            sleep(1);
        }
    
        $this->PIDFile = fopen($this->PIDFileName, 'w');

        if ($this->PIDFile === false) {
            throw new Exception("Can not open '{$this->PIDFileName}'!");
        }

        if (! flock($this->PIDFile, LOCK_EX)) {
            throw new Exception("Can not lock '{$this->PIDFileName}'! Is there another active process?");
        }

        fputs($this->PIDFile, posix_getpid());
    }

    protected function removePIDFile()
    {
        flock($this->PIDFile, LOCK_UN);
        fclose($this->PIDFile);
        $this->PIDFile = null;
        unlink($this->PIDFileName);
    }

    public function OnSignal(int $Signal, $SignalInfo)
    {
        switch ($Signal) {
            case SIGTERM:
                $this->Stop = true;
                break;
            case SIGHUP:
                $this->loadConfig();
                $this->createZones();
                break;
        }
    }

    public function removeZone(Zone $Zone)
    {
        $Index = array_search($Zone, $this->Zones);
        if ($Index !== false) {
            unset($this->Zones[$Index]);
        }
    }

    protected function createZones()
    {
        foreach ($this->Zones as $Dropzone) {
            $Dropzone->destroy();
        }
        $this->Zones = [];

        foreach ($this->Config->getCurrentProfile()->Zones as $ConfZone) {
            $this->Zones[] = new DropZone($this, $ConfZone);
        }
    }

    protected function showZones()
    {
        if (! $this->ZonesVisible) {
            foreach ($this->Zones as $Zone) {
                $Zone->show();
            }
            $this->ZonesVisible = true;
        }
    }

    protected function hideZones()
    {
        if ($this->ZonesVisible) {
            foreach ($this->Zones as $Zone) {
                $Zone->hide();
            }
            $this->ZonesVisible = false;
        }
    }

    protected function handleEvent(XEvent $Event)
    {
        if (($Event->_Name == 'EnterNotify') && ($Event->mode == 'NotifyGrab') && (($Event->state & Config::MODIFIER_SHIFT) == Config::MODIFIER_SHIFT)) {
            $this->DragStarted = true;
            $this->ChangingWindow = 0;
            $this->ChangingWindowWidth = 0;
            $this->ChangingWindowHeight = 0;
            $this->ChangingWindowIsMoving = false;
        }


        if ($this->DragStarted && ($Event->_Name == 'ConfigureNotify')) {
            if ($this->ChangingWindow == 0) {
                $this->ChangingWindow = $Event->window;
                $this->ChangingWindowWidth = $Event->width;
                $this->ChangingWindowHeight = $Event->height;
                $this->ChangingWindowIsMoving = false;
            } elseif (($Event->window == $this->ChangingWindow) && ($Event->width == $this->ChangingWindowWidth) && ($Event->height == $this->ChangingWindowHeight)) {
                $this->showZones();
                $this->ChangingWindowIsMoving = true;
                $this->MatchingZone = $this->getMatchingZone($Event->Param5['x'], $Event->Param5['y'], $Event->width, $Event->height);
                $this->highlightZones();
            }
        }

        if (($Event->_Name == 'FocusOut') && ($Event->mode == 'NotifyUngrab')) {
            $this->hideZones();
            
            if ($this->ChangingWindowIsMoving && $this->MatchingZone != null) {
                WMCtrl::moveWindow($this->ChangingWindow, $this->MatchingZone->Config->Rect);
            }

            $this->DragStarted = false;
            $this->ChangingWindow = 0;
            $this->ChangingWindowWidth = 0;
            $this->ChangingWindowHeight = 0;
            $this->ChangingWindowIsMoving = false;
        }
    }

    protected function highlightZones()
    {
        foreach ($this->Zones as $Zone) {
            if ($Zone == $this->MatchingZone) {
                $Zone->setAlpha(0.8);
            } else {
                $Zone->setAlpha(0.4);
            }
        }
    }

    protected function getMatchingZone(int $X, int $Y, int $Width, int $Height)
    {
        $MouseX = $X + intdiv($Width, 2);
        $MouseY = $Y;
        
        foreach ($this->Zones as $Zone) {
            if ($Zone->Config->Rect->containsPoint($MouseX, $MouseY)) {
                return $Zone;
            }
        }

        return null;
    }

    public function run()
    {
        while (! $this->Stop) {
            foreach ($this->Reader->run() as $Event) {
                $this->handleEvent($Event);
            }
        }

        $this->Shell->close();
        $this->removePIDFile();
    }
}


try {
    App::execute();
} catch (Exception $E) {
    error_log($E->getMessage());
    exit(1);
}
