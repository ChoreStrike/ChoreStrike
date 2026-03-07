/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import {
	Home,
	House,
	Building,
	Menu,
	X,
	Bed,
	Bath,
	Utensils,
	Car,
	Package,
	Armchair,
	Sofa,
	DoorOpen,
	WashingMachine,
	Briefcase,
	BookOpen,
	Gamepad2,
	Dumbbell,
	Tv,
	Trees,
	Baby,
	Trash2,
	Eraser,
	Sparkles,
	ShoppingCart,
	Shirt,
	Wrench,
	CheckCircle,
	Check,
	Circle,
	Plus,
	Minus,
	Edit,
	Trash,
	Settings,
	Fullscreen,
	Maximize,
	Minimize,
	Maximize2,
	Minimize2,
	ChevronDown,
	ChevronUp,
	ChevronLeft,
	ChevronRight,
	MoreHorizontal,
	MoreVertical,
	Search,
	Filter,
	SortAsc,
	SortDesc,
	Star,
	Zap,
	Flame,
	Trophy,
	Award,
	Medal,
	Target,
	TrendingUp,
	Users,
	User,
	UserPlus,
	UserMinus,
	Calendar,
	Clock,
	Bell,
	AlertCircle,
	AlertTriangle,
	Info,
	Lightbulb,
	HelpCircle,
	Heart,
	ThumbsUp,
	MessageSquare,
	Grid,
	List,
	LayoutGrid,
	BarChart3,
	PieChart,
	Activity,
	Share2,
	Download,
	Upload,
	ExternalLink,
	Link,
	Copy,
	RefreshCw,
	RotateCcw,
	Undo,
	Redo,
	Save,
	Printer,
	Battery,
	MapPin,
	Navigation,
	Locate,
	Globe,
	Shield,
	Lock,
	Unlock,
	Eye,
	EyeOff,
	Key,
	LogOut,
	LogIn,
	ArrowLeft,
	ArrowRight,
	ArrowUp,
	ArrowDown,
	ClipboardList,
} from 'lucide-vue-next'

/**
 * Lucide icon registry for ChoreStrike
 *
 * All icons are imported from lucide-vue-next and registered here
 * for consistent usage across the application.
 */
export const icons = {
	// Navigation & UI
	Home,
	House,
	Building,
	Menu,
	X,
	ArrowLeft,
	ArrowRight,
	ArrowUp,
	ArrowDown,
	ChevronDown,
	ChevronUp,
	ChevronLeft,
	ChevronRight,
	MoreHorizontal,
	MoreVertical,
	Search,
	Settings,
	Fullscreen,
	Maximize,
	Minimize,
	Maximize2,
	Minimize2,

	// Actions
	Plus,
	Minus,
	Edit,
	Trash,
	Trash2,
	CheckCircle,
	Check,
	Circle,
	Save,
	Copy,
	RefreshCw,
	Refresh: RefreshCw,
	Close: X,
	RotateCcw,
	Undo,
	Redo,
	Download,
	Upload,
	ExternalLink,
	Link,
	Share2,
	Printer,

	// Rooms
	Bed,
	Bath,
	Utensils,
	Sofa,
	DoorOpen,
	WashingMachine,
	Briefcase,
	BookOpen,
	Gamepad2,
	Dumbbell,
	Tv,
	Trees,
	Baby,
	Armchair,
	Car,
	Package,

	// Tasks & Cleaning
	Eraser,
	Sparkles,
	ShoppingCart,
	Shirt,
	Wrench,

	// Gamification
	Star,
	Zap,
	Flame,
	Trophy,
	Award,
	Medal,
	Target,
	TrendingUp,

	// Users
	Users,
	User,
	UserPlus,
	UserMinus,

	// Time & Scheduling
	Calendar,
	Clock,
	Bell,

	// Info & Feedback
	AlertCircle,
	AlertTriangle,
	Warning: AlertTriangle,
	Lightbulb,
	Info,
	HelpCircle,
	Heart,
	ThumbsUp,
	MessageSquare,

	// Layout & Views
	Grid,
	List,
	LayoutGrid,
	Filter,
	SortAsc,
	SortDesc,

	// Stats
	BarChart3,
	PieChart,
	Activity,

	// Security
	Shield,
	Lock,
	Unlock,
	Eye,
	EyeOff,
	Key,
	LogOut,
	LogIn,

	// Location
	MapPin,
	Navigation,
	Locate,
	Globe,
	Battery,
	Task: ClipboardList,
}

/**
 * Get icon component by name
 *
 * @param {string} name - The icon name
 * @return {object|undefined} The icon component or undefined if not found
 */
export function getIcon(name) {
	return icons[name]
}

/**
 * Check if an icon exists
 *
 * @param {string} name - The icon name
 * @return {boolean} True if the icon exists
 */
export function hasIcon(name) {
	return name in icons
}

export default icons
