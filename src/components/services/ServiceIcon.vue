<template>
	<!-- v4.10.45 — one of the icons a service or a service category may be
	     given (`src/constants/serviceIcons.js`), by name. A name nobody
	     offers renders the default rather than nothing. -->
	<component :is="component" :size="size" aria-hidden="true" />
</template>

<script>
import HelpCircleOutlineIcon from 'vue-material-design-icons/HelpCircleOutline.vue'
import LifebuoyIcon from 'vue-material-design-icons/Lifebuoy.vue'
import AccountGroupOutlineIcon from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountPlusOutlineIcon from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountKeyOutlineIcon from 'vue-material-design-icons/AccountKeyOutline.vue'
import AccountSchoolOutlineIcon from 'vue-material-design-icons/AccountSchoolOutline.vue'
import FolderAccountOutlineIcon from 'vue-material-design-icons/FolderAccountOutline.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import DatabaseArrowUpOutlineIcon from 'vue-material-design-icons/DatabaseArrowUpOutline.vue'
import ArchiveOutlineIcon from 'vue-material-design-icons/ArchiveOutline.vue'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import CalendarOutlineIcon from 'vue-material-design-icons/CalendarOutline.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import EmailOutlineIcon from 'vue-material-design-icons/EmailOutline.vue'
import ChatOutlineIcon from 'vue-material-design-icons/ChatOutline.vue'
import PhoneOutlineIcon from 'vue-material-design-icons/PhoneOutline.vue'
import CellphoneIcon from 'vue-material-design-icons/Cellphone.vue'
import LaptopIcon from 'vue-material-design-icons/Laptop.vue'
import PrinterIcon from 'vue-material-design-icons/Printer.vue'
import WifiIcon from 'vue-material-design-icons/Wifi.vue'
import WebIcon from 'vue-material-design-icons/Web.vue'
import CloudOutlineIcon from 'vue-material-design-icons/CloudOutline.vue'
import ShieldLockOutlineIcon from 'vue-material-design-icons/ShieldLockOutline.vue'
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'
import CogOutlineIcon from 'vue-material-design-icons/CogOutline.vue'
import WrenchOutlineIcon from 'vue-material-design-icons/WrenchOutline.vue'
import ToolboxOutlineIcon from 'vue-material-design-icons/ToolboxOutline.vue'
import BugOutlineIcon from 'vue-material-design-icons/BugOutline.vue'
import SchoolOutlineIcon from 'vue-material-design-icons/SchoolOutline.vue'
import BookOpenOutlineIcon from 'vue-material-design-icons/BookOpenOutline.vue'
import LightbulbOutlineIcon from 'vue-material-design-icons/LightbulbOutline.vue'
import BullhornOutlineIcon from 'vue-material-design-icons/BullhornOutline.vue'
import CartOutlineIcon from 'vue-material-design-icons/CartOutline.vue'
import CashMultipleIcon from 'vue-material-design-icons/CashMultiple.vue'
import TruckOutlineIcon from 'vue-material-design-icons/TruckOutline.vue'
import OfficeBuildingOutlineIcon from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import MapMarkerOutlineIcon from 'vue-material-design-icons/MapMarkerOutline.vue'
import BriefcaseOutlineIcon from 'vue-material-design-icons/BriefcaseOutline.vue'
import ClipboardCheckOutlineIcon from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import HandshakeOutlineIcon from 'vue-material-design-icons/HandshakeOutline.vue'
import HeartOutlineIcon from 'vue-material-design-icons/HeartOutline.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import VideoOutlineIcon from 'vue-material-design-icons/VideoOutline.vue'
import ImageOutlineIcon from 'vue-material-design-icons/ImageOutline.vue'
import ViewGridOutlineIcon from 'vue-material-design-icons/ViewGridOutline.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import { serviceIconName } from '../../constants/serviceIcons.js'

const ICONS = {
	HelpCircleOutline: HelpCircleOutlineIcon,
	Lifebuoy: LifebuoyIcon,
	AccountGroupOutline: AccountGroupOutlineIcon,
	AccountPlusOutline: AccountPlusOutlineIcon,
	AccountKeyOutline: AccountKeyOutlineIcon,
	AccountSchoolOutline: AccountSchoolOutlineIcon,
	FolderAccountOutline: FolderAccountOutlineIcon,
	FolderOutline: FolderOutlineIcon,
	FileDocumentOutline: FileDocumentOutlineIcon,
	DatabaseArrowUpOutline: DatabaseArrowUpOutlineIcon,
	ArchiveOutline: ArchiveOutlineIcon,
	CalendarClock: CalendarClockIcon,
	CalendarOutline: CalendarOutlineIcon,
	ClockOutline: ClockOutlineIcon,
	EmailOutline: EmailOutlineIcon,
	ChatOutline: ChatOutlineIcon,
	PhoneOutline: PhoneOutlineIcon,
	Cellphone: CellphoneIcon,
	Laptop: LaptopIcon,
	Printer: PrinterIcon,
	Wifi: WifiIcon,
	Web: WebIcon,
	CloudOutline: CloudOutlineIcon,
	ShieldLockOutline: ShieldLockOutlineIcon,
	KeyOutline: KeyOutlineIcon,
	CogOutline: CogOutlineIcon,
	WrenchOutline: WrenchOutlineIcon,
	ToolboxOutline: ToolboxOutlineIcon,
	BugOutline: BugOutlineIcon,
	SchoolOutline: SchoolOutlineIcon,
	BookOpenOutline: BookOpenOutlineIcon,
	LightbulbOutline: LightbulbOutlineIcon,
	BullhornOutline: BullhornOutlineIcon,
	CartOutline: CartOutlineIcon,
	CashMultiple: CashMultipleIcon,
	TruckOutline: TruckOutlineIcon,
	OfficeBuildingOutline: OfficeBuildingOutlineIcon,
	MapMarkerOutline: MapMarkerOutlineIcon,
	BriefcaseOutline: BriefcaseOutlineIcon,
	ClipboardCheckOutline: ClipboardCheckOutlineIcon,
	HandshakeOutline: HandshakeOutlineIcon,
	HeartOutline: HeartOutlineIcon,
	Gavel: GavelIcon,
	VideoOutline: VideoOutlineIcon,
	ImageOutline: ImageOutlineIcon,
	ViewGridOutline: ViewGridOutlineIcon,
}

export default {
	name: 'ServiceIcon',

	props: {
		/** An MDI component name from `SERVICE_ICON_NAMES`. */
		name: { type: String, default: '' },
		size: { type: Number, default: ICON_BODY },
	},

	computed: {
		component() {
			return ICONS[serviceIconName(this.name)]
		},
	},
}
</script>
